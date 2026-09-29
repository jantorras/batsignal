</main>
<?php if (BatSignal\Auth::check()): ?>
<footer class="app-footer"><?= te('footer.tagline', ['year' => date('Y')]) ?></footer>
<?php endif; ?>
<script>window.BS_I18N = <?= json_encode(BatSignal\I18n::jsDict(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/app.js?v=4" defer></script>
<script>
// Busy feedback on submit so slow actions (SMTP test, saving) never look frozen.
document.addEventListener('submit', function (e) {
    if (e.defaultPrevented) return;
    var btn = e.submitter;
    if (!btn || btn.dataset.noBusy !== undefined) return;
    var form = e.target;
    if (btn.name) {
        var hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = btn.name;
        hidden.value = btn.value;
        form.appendChild(hidden);
    }
    form.setAttribute('aria-busy', 'true');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span>' + (btn.dataset.busyLabel || btn.textContent.trim());
});
</script>
</body>
</html>
