<?php
namespace BatSignal;

class IncidentManager
{
    public static function process(array $check, array $result): void
    {
        $db = Database::get();
        $checkId = (int)$check['id'];
        $status = $result['status']; // ok | warning | degraded | down
        // "degraded" (some pages with real errors) alerts like "down"; warnings never do.
        $failing = in_array($status, ['down', 'degraded'], true);
        $detail = Msg::encode($result['detail']);

        // 1. Always log the run.
        $stmt = $db->prepare(
            'INSERT INTO check_runs (check_id, status, http_status, response_time_ms, reason_code, detail)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $checkId,
            $status,
            $result['http_status'],
            $result['response_time_ms'],
            $result['reason_code'],
            $detail,
        ]);

        // 2. Update failure streak.
        $consecutiveFailures = $failing ? (int)$check['consecutive_failures'] + 1 : 0;

        $db->prepare('UPDATE checks SET last_run_at = NOW(), consecutive_failures = ? WHERE id = ?')
            ->execute([$consecutiveFailures, $checkId]);

        // 3. Incident lifecycle.
        $openIncident = self::findOpenIncident($checkId);

        if ($failing && $consecutiveFailures >= (int)$check['failure_threshold']) {
            if (!$openIncident) {
                self::openIncident($check, $result, $detail);
            } else {
                $db->prepare('UPDATE incidents SET reason_code = ?, last_error = ? WHERE id = ?')
                    ->execute([$result['reason_code'], $detail, $openIncident['id']]);
            }
        } elseif (!$failing && $openIncident) {
            self::closeIncident($check, $openIncident, $result);
        }
    }

    private static function findOpenIncident(int $checkId): ?array
    {
        $stmt = Database::get()->prepare(
            "SELECT * FROM incidents WHERE check_id = ? AND status = 'open' ORDER BY opened_at DESC LIMIT 1"
        );
        $stmt->execute([$checkId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private static function openIncident(array $check, array $result, ?string $detail): void
    {
        $db = Database::get();
        $db->prepare(
            'INSERT INTO incidents (check_id, status, reason_code, last_error) VALUES (?, "open", ?, ?)'
        )->execute([$check['id'], $result['reason_code'], $detail]);

        $incidentId = (int)$db->lastInsertId();
        self::notify($check, $result, $incidentId, 'opened');
    }

    private static function closeIncident(array $check, array $incident, array $result): void
    {
        $db = Database::get();
        $db->prepare("UPDATE incidents SET status = 'closed', closed_at = NOW() WHERE id = ?")
            ->execute([$incident['id']]);

        self::notify($check, $result, (int)$incident['id'], 'resolved', $incident);
    }

    private static function notify(array $check, array $result, int $incidentId, string $type, ?array $incident = null): void
    {
        $db = Database::get();
        $settings = [];
        foreach ($db->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('notify_email', 'mail_lang')") as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        // Emails go out in the language chosen in Settings, whoever triggered the run.
        $lang = I18n::isValid($settings['mail_lang'] ?? null) ? $settings['mail_lang'] : I18n::DEFAULT;

        $siteStmt = $db->prepare('SELECT * FROM sites WHERE id = ?');
        $siteStmt->execute([$check['site_id']]);
        $site = $siteStmt->fetch();
        $siteName = $site['name'] ?? I18n::t('mail.unknown_site', [], $lang);

        if ($type === 'opened') {
            $solution = SolutionProvider::suggest((string)$result['reason_code'], $lang);
            $subject = I18n::t('mail.opened_subject', ['site' => $siteName, 'check' => I18n::checkName($check['name'], $lang), 'title' => $solution['title']], $lang);
            $body = self::renderOpenedEmail($siteName, $check, $result, $solution, $lang);
        } else {
            $subject = I18n::t('mail.resolved_subject', ['site' => $siteName, 'check' => I18n::checkName($check['name'], $lang)], $lang);
            $body = self::renderResolvedEmail($siteName, $check, $incident['opened_at'] ?? null, $lang);
        }

        $sent = Mailer::send($subject, $body);

        $db->prepare('INSERT INTO notifications (incident_id, type, email_to, success) VALUES (?, ?, ?, ?)')
            ->execute([$incidentId, $type, (string)($settings['notify_email'] ?? ''), $sent ? 1 : 0]);
    }

    private static function renderOpenedEmail(string $siteName, array $check, array $result, array $solution, string $lang): string
    {
        $h = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $l = fn(string $key) => $h(I18n::t($key, [], $lang));
        $detail = nl2br($h(Msg::renderMsg($result['detail'], $lang)));
        $site = $h($siteName);
        $name = $h(I18n::checkName($check['name'], $lang));
        $url = $h($check['url']);
        $title = $h($solution['title']);
        $suggestion = $h($solution['suggestion']);
        $now = date('Y-m-d H:i:s');

        return <<<HTML
        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; background:#111; color:#f2f2f2; padding: 24px; border-radius: 8px;">
            <h2 style="color:#f4c542; margin-top:0;">{$l('mail.opened_heading')}</h2>
            <p><strong>{$l('mail.web')}:</strong> {$site}<br>
               <strong>{$l('mail.check')}:</strong> {$name}<br>
               <strong>{$l('mail.url')}:</strong> {$url}<br>
               <strong>{$l('mail.moment')}:</strong> {$now}</p>
            <h3 style="color:#f4c542;">{$title}</h3>
            <p style="font-family: Consolas, monospace; font-size: 13px; background:#1b1d23; padding:12px; border-radius:6px; line-height:1.6;">{$detail}</p>
            <h4 style="color:#f4c542;">{$l('mail.solution')}</h4>
            <p>{$suggestion}</p>
            <hr style="border-color:#333;">
            <p style="font-size:12px;color:#888;">{$l('mail.footer')}</p>
        </div>
        HTML;
    }

    private static function renderResolvedEmail(string $siteName, array $check, ?string $downSince, string $lang): string
    {
        $h = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $l = fn(string $key) => $h(I18n::t($key, [], $lang));
        $site = $h($siteName);
        $name = $h(I18n::checkName($check['name'], $lang));
        $since = $downSince ? $h($downSince) : $l('mail.unknown');
        $now = date('Y-m-d H:i:s');

        return <<<HTML
        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; background:#111; color:#f2f2f2; padding: 24px; border-radius: 8px;">
            <h2 style="color:#4caf50; margin-top:0;">{$l('mail.resolved_heading')}</h2>
            <p><strong>{$l('mail.web')}:</strong> {$site}<br>
               <strong>{$l('mail.check')}:</strong> {$name}<br>
               <strong>{$l('mail.down_since')}:</strong> {$since}<br>
               <strong>{$l('mail.resolved_at')}:</strong> {$now}</p>
            <hr style="border-color:#333;">
            <p style="font-size:12px;color:#888;">{$l('mail.footer')}</p>
        </div>
        HTML;
    }
}
