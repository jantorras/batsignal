# BatSignal

Panell de monitoratge per a webs i serveis, pensat per detectar coses que un
uptime checker clàssic (Uptime Kuma, etc.) es deixa passar: una pàgina que
respon `200 OK` però mostra un error de PHP, un certificat SSL a punt de
caducar, o un temps de resposta anormal.

Aquesta és la **Fase 1 (MVP)**: panell web + checks HTTP i SSL + notificacions
per correu amb suggeriment de solució. La detecció d'errors renderitzats amb
navegador i les captures de pantalla (Fase 2, amb Playwright) encara no hi
són.

## Estructura

```
BatSignal/
  app/            Lògica (no accessible directament via navegador)
    config/       config.php (credencials locals, no versionar)
    lib/          Database, Auth, Mailer, SolutionProvider, Checks/...
  cron/
    run_checks.php   Script CLI que executa els checks pendents
  db/
    schema.sql    Esquema MySQL/MariaDB
  public/         Arrel web (és el document root que serveix el contenidor `web`)
  vendor/phpmailer/  PHPMailer vendoritzat a mà (no hi ha Composer instal·lat)
```

## Primer arrencada

BatSignal està pensat per córrer només amb Docker, en un servidor Linux
(vegeu **Desplegament en un servidor**, més avall). El propi desplegament ja
deixa la base de dades creada, l'usuari administrador creat, i el
`runner` executant `cron/run_checks.php` cada minut sol — no cal cap pas
manual addicional ni programar cap tasca externa.

Un cop el panell és accessible:

1. Entra amb l'usuari que vas crear durant la instal·lació.
2. Ves a **Configuració** per posar les dades SMTP i l'email (o emails,
   separats per comes) on vols rebre les alertes. Pots enviar un correu de
   prova des de la mateixa pàgina.
3. Ves a **Webs** per afegir les teves webs; cada web queda vigilada
   automàticament (portada, totes les pàgines i SSL).

## Tipus de checks

En afegir una web es creen automàticament aquests tres checks (a una web
existent sense checks, el botó "Activar monitoratge complet" fa el mateix):

| Check | Freqüència | Què fa |
|---|---|---|
| **Web en línia (portada)** | 2 min | La home respon amb HTTP 200, sense errors de PHP ni pàgina en blanc, i en menys de 6 s. |
| **Totes les pàgines** | 30 min | Descobreix les pàgines (robots.txt → sitemap, i enllaços interns des de la home) i en revisa fins a 100: HTTP 4xx/5xx, enllaços trencats, errors de PHP, pàgines en blanc, timeouts i pàgines lentes. |
| **Certificat SSL** | 6 h | Validesa i domini del certificat; avís als 30 dies i correu d'alerta als 14. |

La detecció d'errors de PHP (`app/lib/PageInspector.php`) és sempre activa:
reconeix el format real dels errors de PHP ("Warning: … in fitxer.php on
line N", "Fatal error", "Uncaught …"), les pantalles d'"error crític" de
WordPress, els errors de connexió a BD i les pàgines en blanc. Això cobreix
el cas que va motivar el projecte (versió de PHP incorrecta en una pàgina
concreta). A cada check s'hi poden afegir textos prohibits propis.

També es poden afegir checks personalitzats per a pàgines concretes (p. ex.
que el checkout contingui un text). Des de la fitxa de cada web, **Executar
ara** llança tots els seus checks a l'instant.

El crawler no segueix enllaços externs, fitxers (PDF, imatges…), `wp-admin`,
`wp-login` ni enllaços de logout, i descarta els paràmetres de query dels
enllaços descoberts per no disparar-se amb filtres infinits.

Cada check obre un incident (i envia correu) després de N fallades
consecutives configurables per check (`failure_threshold`), per evitar avisos
per un timeout puntual. Quan el check torna a OK, l'incident es tanca
automàticament i s'envia un correu de resolució.

## Desplegament en un servidor (Docker, sense saber Docker)

Tot es fa amb un sol script amb menú, `batsignal.sh`. Requisits: un servidor
Linux (Ubuntu, Debian, CentOS, Rocky, Alma...) amb accés d'administrador.
Docker no cal tenir-lo: l'script l'instal·la.

1. Entra per SSH al servidor i clona el repositori (és públic, no cal cap
   autenticació):

   ```
   git clone https://github.com/jantorras/batsignal.git /opt/batsignal
   cd /opt/batsignal
   ```
2. Executa l'script:

   ```
   sudo bash batsignal.sh
   ```
3. Tria **1) Instal·lar**. L'script comprova el servidor, instal·la Docker si
   cal, genera les contrasenyes, construeix i arrenca tot, crea l'usuari
   administrador i t'ensenya l'adreça del panell (`http://IP:8080`).

> Si surt `$'\r': command not found`, el fitxer s'ha copiat amb finals de
> línia de Windows: `sed -i 's/\r$//' batsignal.sh` i torna-hi.

**Si alguna cosa no funciona** (no respon la web, el servidor s'ha reiniciat,
els checks no s'executen...): `sudo bash batsignal.sh` → **3) Diagnosticar i
reparar**. Comprova i arregla sol:
- Docker aturat;
- serveis caiguts;
- base de dades o web que no responen;
- runner encallat;
- disc ple;
- port ocupat;
- fitxers amb finals de línia de Windows.

El **vigilant automàtic** (opció 11, s'activa en instal·lar) fa aquesta
mateixa reparació cada 5 minuts i una còpia de la BD cada nit (`backups/`).

Altres opcions del menú:
- estat;
- logs;
- còpies i restauració;
- actualitzar;
- usuaris, incloent la contrasenya oblidada;
- canviar port, domini o zona horària;
- desinstal·lar.

Tot funciona també per ordres: `sudo bash batsignal.sh help`.

### Accés per domini (a més del IP:port)

Per defecte el panell s'obre amb `http://IP:PORT`. Des de **Configuració →
Domini** (o `sudo bash batsignal.sh domain`) es pot afegir un nom, p. ex.
`batsignal.empresa.local`, perquè no calgui recordar cap port. Es pregunta
com resoldrà el domini:

- **Domini intern, sense DNS públic** (el cas habitual per a un ús intern):
  s'hi accedeix per HTTP pla al port que triïs (per defecte el 80). Cal que
  el nom resolgui a la IP d'aquest servidor des dels ordinadors que hi
  accedeixin (DNS intern de l'empresa, o una entrada al fitxer *hosts* de
  cada equip).
- **Domini amb DNS públic apuntant a aquest servidor:** HTTPS automàtic amb
  Let's Encrypt. Calen els ports 80 i 443 lliures (Let's Encrypt els
  necessita exactament aquests per validar el domini); si un altre servei ja
  els fa servir en aquest mateix servidor, cal alliberar-los o triar la
  opció sense HTTPS.

Es pot desactivar en qualsevol moment des del mateix menú; l'accés per
IP:port continua funcionant sempre, encara que hi hagi un domini configurat.
Tècnicament s'afegeix un tercer contenidor (`proxy`, Caddy) que només
arrenca quan hi ha un domini configurat.

**Si el port 80 ja el fa servir aaPanel** (perquè el servidor també allotja
altres webs), en mode sense HTTPS l'script et proposarà un port alternatiu
(p. ex. 81) i, si detecta que aaPanel gestiona Nginx o Apache, oferirà
afegir-hi automàticament un proxy invers perquè el domini funcioni igualment
sense haver d'indicar el port (aaPanel, al port 80, redirigeix internament
cap al 81). Sempre valida la configuració abans de recarregar-la i mai toca
un fitxer que no hagi creat ell mateix. Amb OpenLiteSpeed (o qualsevol altra
cosa) no s'automatitza; l'script indica els passos per fer-ho a mà des
d'aaPanel.

**Importar dades d'una instal·lació anterior:** exporta la BD `batsignal`
(phpMyAdmin → Exportar, o `mysqldump`), copia el fitxer `.sql`/`.sql.gz` a
`backups/` al servidor i tria **7) Restaurar**. S'hi aplicaran les
migracions que faltin.

Com està muntat: tres contenidors (`db` MariaDB, `web` Apache+PHP que només
serveix `public/`, `runner` que executa els checks cada minut); dades en un
volum Docker; configuració i contrasenyes al `.env` (el crea l'script, no el
comparteixis). `GET /health.php` retorna l'estat, útil per a Uptime Kuma, i
al `.env` es pot posar `HEARTBEAT_URL` per a un monitor *push* que vigili el
vigilant.

## Gravetat: quan és "caiguda" i quan no

Una pàgina amb problemes no fa que una web que funciona surti com a caiguda:

| Estat | Quan | Incident + correu |
|---|---|---|
| **Caiguda** | La portada no respon (després d'un reintent) o dona un error, o més de la meitat de les pàgines (i 3+) fallen | Sí |
| **Amb errors** | Algunes pàgines tenen errors reals: PHP, 5xx, pàgina en blanc | Sí |
| **Avís** | Enllaços trencats (4xx), pàgines lentes o que no responen a temps | No |

- Timeouts, errors de connexió i 5xx es **reintenten** una vegada abans de comptar.
- La lentitud **mai** és una caiguda: per a webs lentes de mena, puja el temps d'espera del check.
- Els enllaços trencats indiquen des de quina pàgina estan enllaçats.

**Webs grans:** el check "Totes les pàgines" recorda les pàgines descobertes
(`crawl_pages`) i en revisa un nombre per passada (per defecte 100): primer
la portada, després les que van fallar, després les mai revisades i les més
antigues. Així les webs grans es cobreixen per torns (la fitxa diu cada
quantes passades es revisa la web sencera). Límit: 20.000 pàgines per web;
les pàgines que ningú enllaça durant 14 dies s'obliden.

## Grups

A **Grups** es creen grups (p. ex. "Fire") amb un color i s'hi marquen les
webs membres; una web pot estar en diversos grups. Cada grup mostra el seu
estat global (el pitjor dels seus membres), i el Dashboard i la llista de
webs es poden filtrar per grup.

## Idiomes

Català, castellà i romanès. Cada usuari tria l'idioma amb el selector de la
barra superior (o de la pantalla de login) i queda desat al seu compte; els
correus d'alerta s'envien en l'idioma triat a **Configuració → Idioma dels
correus**.

- Textos: `app/lang/{ca,es,ro}.php` (el català és la referència; tota clau
  ha d'existir als tres fitxers amb els mateixos `{paràmetres}`).
- Els resultats dels checks es desen com a missatges neutres (clau +
  paràmetres, `app/lib/Msg.php`) i es tradueixen en mostrar-los, així
  l'historial es llegeix en l'idioma de qui el mira.
- Els noms dels checks creats automàticament es mostren traduïts mentre no
  els canviïs; un nom personalitzat es respecta tal qual.
- Per afegir un idioma: copia `ca.php` a `xx.php`, tradueix-lo i afegeix
  `'xx' => 'Nom'` a `I18n::LANGS` (`app/lib/I18n.php`).

## Pendent (properes fases)

- **Fase 2**: worker Node.js + Playwright per checks amb navegador real
  (detectar errors JS, renderitzat complet) i adjuntar una captura de
  pantalla a l'email de l'incident.
- **Fase 3**: polir dashboard/historial d'uptime.
- **Fase 4**: solucions suggerides generades amb IA (ja hi ha el punt
  d'extensió a `app/lib/SolutionProvider.php`), i altres canals d'avís
  (Telegram/Discord) a més del correu.

## Notes

- Si BatSignal corre al mateix servidor que allotja les webs que vigila, i
  aquell servidor cau, BatSignal cau amb ell i no pot avisar-te'n. Per això
  es recomana un servidor Linux dedicat, només per a BatSignal (secció
  **Desplegament en un servidor**).
- `app/config/config.php` no conté cap credencial (les llegeix de variables
  d'entorn, que posa Docker a partir del `.env`), per això sí que està
  versionat.
