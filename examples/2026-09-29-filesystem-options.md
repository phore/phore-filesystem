# Dateisystemzugriff ohne Consumer-Wrapper

| Datum | Benutzername | Kurzbeschreibung |
|---|---|---|
| 2026-09-29 | dermatthes | §§ 1–12: Direkte Operationen, vererbte Options, Assertions und Schiller-Migration entworfen |

## § 1 Normalfall: Operation aufrufen, Fehler durchreichen

**Status: API-Entwurf, keine implementierte Sicherheitsfunktion.** Die drei PHP-Beispiele neben dieser Datei verwenden ausschließlich vorhandene APIs. Hier ist § 1 ebenfalls Bestand; die Options, zusätzlichen Assertions und Front-Matter-Erweiterungen ab § 2 sind Vorschläge. Die folgenden Markdown-Codeblöcke sind Anwendungsausschnitte nach Composer-Autoloading, keine vollständigen PHP-Dateien. [neu]

Gemeinsamer Beispielkontext: `/srv/site/docs` ist der Document Root, `/srv/site/theme/_tpl` die Vorlagenwurzel. `_tpl/_root/docs/index.md` enthält `# Start` mit abschließendem Zeilenumbruch. `docs/.shiller.yml` enthält `template_dir: ../theme/_tpl`. Alle genannten Quellen existieren, soweit ein Fehlerfall nicht ausdrücklich anderes beschreibt. [neu]

```php
$content = phore_file('/srv/site/theme/_tpl/_root/docs/index.md')->get_contents();
phore_file('/srv/site/docs/index.md')->mkdir()->set_contents($content);
// Ergebnis: docs/index.md enthaelt exakt "# Start\n".

$config = phore_file('/srv/site/docs/.shiller.yml')->get_yaml();
// Ergebnis: ['template_dir' => '../theme/_tpl']; kein eigenes YAML-Parsing.
```

Keine vorgeschalteten `file_exists()`-/`is_readable()`-Guards, keine privaten `readFile()`-Helper, kein `try/catch` und keine neue allgemeine `RuntimeException` um Phore herum. Fehlender Pfad, fehlende Rechte und ungültiges YAML werden an der zuständigen Dateioperation diagnostiziert. Abfangen nur für eine echte Behandlung, etwa einen vereinbarten Fallback oder Retry; bloßes Umbenennen der Fehlermeldung ist keine Behandlung. Die äußere Anwendung entscheidet über die Darstellung gegenüber ihrem Nutzer, insbesondere über sensible Pfade in öffentlichen HTTP-Antworten. [neu]

## § 2 Zugriffspolitik einmal am Einstieg binden

`Phore\FileSystem\FilesystemOptions` wird ein unveränderliches Value Object. `phore_uri()`, `phore_file()` und `phore_dir()` erhalten einen optionalen benannten Parameter `options`; direkte Konstruktoren erhalten ihn zusätzlich nach dem bisherigen internen Relativpfad-Parameter. Vorhandene positionale Argumente bleiben gültig. [neu]

Diese Variante ersetzt den unbeschränkten Zugriff aus § 1: [neu]

```php
use Phore\FileSystem\FilesystemOptions;

$options = new FilesystemOptions(
    rootPath: '/srv/site/docs',
    followSymlinks: false,
);
$docs = phore_dir('/srv/site/docs', options: $options)->assertDirectory();
$config = $docs->withSubPath('.shiller.yml')->asFile()->get_yaml();
// Gleicher Rueckgabewert; Link- und Root-Regeln gelten auch fuer die abgeleitete Datei.
```

Für einen einzelnen Zugriff ist dieselbe Policy direkt verwendbar: [neu]

```php
$config = phore_file('/srv/site/docs/.shiller.yml', options: $options)->get_yaml();
```

Vorgeschlagene Defaults: `rootPath: null`, `followSymlinks: true`, `allowHardLinks: true`, `requireAtomicContainment: false`. Bestehende Aufrufe behalten damit ihr bisheriges unbeschränktes Verhalten; Schiller setzt seine strengere Policy ausdrücklich. Relative Roots werden beim Binden einmal absolut aufgelöst und anschließend nicht bei jedem Zugriff gegen ein möglicherweise verändertes Arbeitsverzeichnis interpretiert. Die Root muss bereits ein Verzeichnis sein; ein ungültiger oder nicht vorhandener Root-Wert ist ein Fehler, kein Rückfall auf unbeschränkten Zugriff. [neu]

## § 3 Typwechsel und Voraussetzungen unterscheiden

| Aufruf | Vertrag |
|---|---|
| `asFile()` / `asDirectory()` | Vorhanden: nur typisierte Sicht; keine Existenzgarantie, keine Anlage. |
| `assertFile()` / `assertDirectory()` | Vorhanden: Existenz und Typ verlangen; mit `true` darf angelegt werden. |
| `assertReadable()` / `assertWritable()` | Vorhanden: eine eigenständig benötigte Zugriffs-Voraussetzung verlangen. |
| `assertNoSymlinks()` | Neu: bestehenden Pfadanteil einschließlich Eltern prüfen und ein typgleiches Objekt mit dauerhaft verschärfter No-Follow-Policy zurückgeben; fehlende Blattdatei allein ist kein Link-Fehler. |
| `assertRelativePath()` | Neu: nichtleeren relativen lokalen Pfad verlangen; NUL, absolute Pfade, Schemes, Backslashes und `..` oberhalb des Ausgangspunkts ablehnen. Keine Dateianlage. |
| `assertFileTarget()` | Neu: Dateiziel vorab verlangen, ohne es anzulegen; vorhandenes Blatt muss eine schreibbare Datei sein, vorhandene Eltern müssen Verzeichnisse sein, fehlende Eltern müssen unter der Policy anlegbar sein. |

Die letzten drei Zeilen sind Entwurf. `assertNoSymlinks()` liefert absichtlich ein neues eingeschränktes Objekt: Die Rückgabe verwenden; bereits vorher erzeugte Objekte werden nicht nachträglich geändert. Im Standardzugriff genügt die Policy am Einstieg. Zusätzliche Assertions sind für tatsächliche Vorabbedingungen, nicht als Pflicht-Kette vor jedem Lesen gedacht. Auch alle bestehenden Assert-Methoden müssen die gebundene Policy respektieren und weiterreichen. [neu]

Unabhängige Variante ohne Root-Grenze, wenn ausschließlich Links verboten werden sollen: [neu]

```php
$content = phore_file('/srv/site/docs/index.md')
    ->assertNoSymlinks()
    ->get_contents();
```

Normale boolesche Abfragen bleiben für fachliche Auswahl erlaubt, etwa Dateien statt Verzeichnisse auswählen oder eine optionale Konfiguration verwenden. Ein Policy-Verstoß darf dabei nicht stillschweigend als `false` beziehungsweise „Datei fehlt“ verschwinden. PHPs abschaltbares `assert()` ist nur eine Darstellung erwarteter Beispielwerte, niemals Ersatz für produktive `assert*()`-Prüfungen. [neu]

## § 4 Vererbung darf an keiner Ebene abbrechen

Der gebundene Zugriffskontext bleibt bei `as*`, `assert*`, `abs`, `clean`, `rel`, `join`, `withSubPath`, `withRelativePath`, `withFileName`, `withFileExtension`, Eltern-/Verzeichniszugriffen, Kopien und Clones erhalten. Auch `genWalk`, `walk`, `walkR`, `list`, `listFiles` und `getListSorted` geben Objekte mit derselben Policy an Aufrufer und Callbacks weiter. Ein Wechsel der Pfaddarstellung darf den intern absolut gebundenen Zugriffspfad nicht entkoppeln. `rel()` ist insbesondere keine Möglichkeit, über ein neues CWD andere Dateien zu öffnen. [neu]

`phore_file($boundedUri)` und die entsprechenden Uri-/Directory-Factories übernehmen den Kontext eines bereits gebundenen Objekts. Explizit schwächere Options für dieses Objekt werden abgelehnt; Einschränkungen dürfen nur enger werden. Der bewusst neue Einstieg aus einem nackten String ist eine neue Autorisierungsentscheidung: `(string) $file` trägt keine Policy. Deshalb innerhalb einer Library mit Objekten weiterarbeiten und nicht aus ihren Strings unbeschränkte Objekte rekonstruieren. [neu]

`fopen()`/`gzopen()` und `FileStream::getFileObject()` behalten den Kontext. Bei Kopieren/Verschieben werden Quell- und Zielrechte getrennt durchgesetzt; ein Zielobjekt mit eigener Root ersetzt nicht die Quellen-Policy. Temporäre Dateien für eine begrenzte Schreiboperation entstehen innerhalb der erlaubten Root. Root-begrenzte APIs dürfen unbekannte Stream-Wrapper und nicht abgesicherte ZIP-/Subprozess-Pfade nicht stillschweigend benutzen, sondern müssen diese Operation vor Seiteneffekten als nicht unterstützt ablehnen. [neu]

## § 5 Symlinks und Root-Grenzen

`followSymlinks: false` bedeutet **Fehler statt Überspringen**: Links am Blatt, in Elternverzeichnissen, an der Root oder in ihrem angegebenen Zugangspfad sind verboten. Beim Walk wird vor jeder Rekursion und vor jedem Dateizugriff geprüft, auch bevor ein Dateinamenfilter angewendet wird. Kaputte Links und Link-Schleifen sind ebenfalls Fehler. Diese Semantik passt zu Schillers bisheriger Ablehnung verlinkter Vorlagen. [neu]

Die folgende unabhängige Variante erlaubt Links, aber nur innerhalb der Root. `docs/current.yml` zeige dabei auf `docs/config/production.yml`: [neu]

```php
$linkedDocs = phore_dir('/srv/site/docs', options: new FilesystemOptions(
    rootPath: '/srv/site/docs',
    followSymlinks: true,
))->assertDirectory();
$config = $linkedDocs->withSubPath('current.yml')->asFile()->get_yaml();
```

Das aufgelöste Ziel muss innerhalb der Root bleiben. `/srv/site/docs-backup` ist kein Kind von `/srv/site/docs`; ein einfacher String-Präfixvergleich genügt nicht. `../`, absolute Pfade, Stream-Schemes und Links nach außen müssen abgewehrt werden. Die ursprüngliche Segmentfolge wird geprüft: Ein `link/../datei` darf nicht zuerst rein textuell verkürzt werden und dadurch die Link-Prüfung umgehen. `withSubPath()` nutzt im gebundenen Kontext intern dieselbe relative Pfad-Assertion; der Consumer braucht dafür keine eigene Normalisierungsfunktion. [neu]

Neue Ziele verlangen die Prüfung des nächsten vorhandenen Elternverzeichnisses und jedes danach angelegten Segments; nur `realpath($ziel)` reicht nicht, weil PHP bei nicht vorhandenen Pfaden `false` liefert. Die Pfadgrenze gilt bei Lesen, Schreiben, Anlegen, Umbenennen, Kopieren, Löschen und Metadatenänderungen gleichermaßen. Plattformen mit nicht zuverlässig prüfbaren Junctions/Reparse-Points müssen begrenzte Zugriffe ablehnen statt einen nicht belegten Schutz zu versprechen. Grundlage: [PHP realpath](https://www.php.net/manual/en/function.realpath.php). [neu]

## § 6 Gleichzeitige Änderungen und Hardlinks ehrlich abgrenzen

Prüfen mit `realpath()`/`lstat()` und anschließendes Öffnen über einen Pfad sind nicht atomar. Für Bäume, die ein anderer Akteur gleichzeitig verändern kann, wird `requireAtomicContainment: true` vorgeschlagen. Dann sind handle-relative, vom Betriebssystem abgesicherte Operationen erforderlich; Linux `openat2()` bietet entsprechende Auflösungsregeln. Ein Backend muss nicht nur das Lesen, sondern alle verwendeten Schreib-/Rename-/Delete-Operationen absichern. Fehlt diese Fähigkeit, wird vor dem Zugriff eine `UnsupportedFilesystemPolicyException` geworfen; kein stiller Fallback. Das ist noch kein vorhandenes PHP-Backend. Grundlage: [Linux openat2](https://man7.org/linux/man-pages/man2/openat2.2.html). [neu]

Hardlinks sind kein Symlink-Zielpfad: Dieselben Dateidaten können unter Namen innerhalb und außerhalb einer Root erreichbar sein. Eine Pfadgrenze allein beweist daher keine Datenherkunft oder ausschließliche Datenzugehörigkeit. `allowHardLinks: false` soll reguläre Dateien mit mehreren Links ablehnen, auch vor destruktiven Schreibzugriffen; das gilt nicht für den normalen Link-Zähler von Verzeichnissen. Selbst diese Momentaufnahme verhindert keine spätere neue Verlinkung durch andere Akteure. Für eine harte Isolationsgarantie werden zusätzlich kontrollierte Besitz-/Schreibrechte oder eine Dateisystem-/Prozessgrenze benötigt. Die Library ist keine Sandbox gegen beliebigen PHP-Code, der native Dateioperationen aufruft. [neu]

Diese strengere, unabhängige Variante ist nur mit einem passenden Backend ausführbar: [neu]

```php
$isolated = phore_dir('/srv/site/docs', options: new FilesystemOptions(
    rootPath: '/srv/site/docs',
    followSymlinks: false,
    allowHardLinks: false,
    requireAtomicContainment: true,
))->assertDirectory();
$content = $isolated->withSubPath('index.md')->asFile()->get_contents();
```

## § 7 Traversierung direkt verwenden

Dieser neue Einstieg bindet den Template-Root, unabhängig von `$docs`: [neu]

```php
$templateOptions = new FilesystemOptions(
    rootPath: '/srv/site/theme/_tpl',
    followSymlinks: false,
);
$templates = phore_dir('/srv/site/theme/_tpl', options: $templateOptions)->assertDirectory();
$files = [];
foreach ($templates->listFiles('*.md', recursive: true) as $file) {
    $files[$file->getRelPath()] = $file->get_contents();
}
// Beispielsweise: ['_root/docs/index.md' => "# Start\n", ...].
```

Keine eigene Rekursions-Queue, kein `is_link()` im Callback und keine erneute Read-Prüfung. Der Filter darf die Navigation durch Verzeichnisse nicht verhindern. Generatoren dürfen beim Iterieren Exceptions werfen; die Policy darf nicht erst nach `yield` oder nach dem Betreten eines Links geprüft werden. Bei erlaubten Links verhindert eine Erkennung bereits im aktuellen Rekursionspfad besuchter Verzeichnisidentitäten Zyklen; ein erreichtes Tiefenlimit wird gemeldet und liefert keinen still unvollständigen Plan. [neu]

Für deterministische Verarbeitung kann Schiller die vorhandene sortierte Liste verwenden und Verzeichnisse fachlich ausfiltern. Diese Auswahl ist keine Fehler-Guard-Schicht. Das vorhandene `getRelPath()` bezeichnet weiterhin den relativen Ableitungspfad des Objekts; nicht ungefragt auf den letzten Unterverzeichnis-Walk umdefinieren. Ein frisch gebundener Traversierungs-Einstieg beginnt ohne Relativpfad-Präfix. [neu]

## § 8 Neue Dateiziele ohne vorzeitiges Schreiben prüfen

Der folgende Ablauf ergänzt `$docs` aus § 2. Der gezeigte Plan enthält bereits fachlich eindeutige, relative Ziele: [neu]

```php
$plan = ['index.md' => "# Start\n", '_includes/nav.html' => "<nav>Start</nav>\n"];

foreach ($plan as $relative => $content) {
    $docs->withSubPath($relative)->assertFileTarget();
}
foreach ($plan as $relative => $content) {
    $docs->withSubPath($relative)->asFile()->mkdir()->set_contents($content);
}
// Ergebnis: beide Dateien geschrieben, fehlende Eltern erst im zweiten Durchlauf angelegt.
```

`assertFileTarget()` erzeugt weder Elternverzeichnisse noch Platzhalterdateien und prüft auch existierende Eltern auf falschen Typ. Ein Konflikt zwischen zwei erst geplanten Pfaden, etwa `a` und `a/b`, bleibt dagegen eine Eigenschaft des gesamten Schiller-Plans und muss dort vor dem ersten Schreiben erkannt werden. Die Vorprüfung ist keine Transaktion: Spätere I/O-Fehler können zu einem teilweise geschriebenen Plan führen; jede Schreiboperation prüft ihre Voraussetzungen erneut. [neu]

## § 9 Front Matter einmal lesen, nicht neu implementieren

Bereits vorhanden sind `get_yaml()`, `get_front_matter()` sowie `put_front_matter()`. Für Schillers zuerst vollständig aufgebauten Schreibplan fehlen jedoch eine optionale Header-Erkennung und ein verlustfreier Snapshot mit reinem Rendern ohne Schreibzugriff. Diese Lücke nicht durch Regex-/YAML-Helper oder temporäre Dateien in Schiller schließen. [neu]

Vorschlag: `get_front_matter(?string $cast = null, bool $required = true)` liefert mit `required: false` nur bei **fehlendem** Header `null`; ein vorhandener kaputter Header bleibt eine `FileParsingException` mit Dateipfad und Position. `FrontMatterFile` erhält `sourceContents` als unveränderlichen Originaltext und `render(): string` als gemeinsamen Serializer, den auch `put_front_matter()` nutzt. Unveränderte Dokumente rendern bytegleich; erst eine echte Header-/Body-Änderung serialisiert neu. Unbekannte Headerwerte bleiben erhalten; bei neu serialisiertem YAML wird keine Erhaltung von Kommentaren/Formatierung versprochen. Der Snapshot entsteht aus genau einem Lesevorgang. [neu]

Der folgende Ausschnitt ergänzt `$templates` aus § 7; `index.raven.md` sei eine Markdown-Vorlage mit gültigem `schiller`-Block und Body `# Raven\n`. Tag-Auswahl und `schiller`-Schema sind hier bereits fachlich geprüft: [neu]

```php
$source = $templates->withSubPath('index.raven.md')->asFile();
$document = $source->get_front_matter();
$unchanged = $document->sourceContents;

$document->header['schiller']['instructions'] = ['tpl:/instructions/style.md'];
$plan = ['index.md' => $document->render()];
// Nur geplant: neuer Header plus derselbe Body, noch keine Zieldatei geschrieben.
```

Bei `.template`-Dateien kommt `$document->content` in den Plan, damit nur dort der Metadaten-Header entfällt. Markdown ohne Header kann beim Indexieren mit `required: false` übersprungen werden, ohne einen erwarteten Normalfall per Catch zu behandeln. Prüfungen für Schiller-Tags, `target` und `instructions` gehören nicht in die generische Filesystem-Library. [neu]

## § 10 Konkrete Kürzung von SchillerAutomation

| Bisher in `src/Automation/SchillerAutomation.php` | Direkter Ersatz / verbleibende Zuständigkeit |
|---|---|
| `directory()` | Gebundene `phore_dir(..., options: ...)`-Objekte und `assertDirectory()`; Properties als `PhoreDirectory` statt rekonstruierter Pfadstrings. |
| `readFile()` | `$file->get_contents()`; bei reiner Referenzprüfung `assertFile()->assertReadable()` ohne unnötigen Inhalts-Read. |
| `walk()` | `listFiles()`, `genWalk()` oder `getListSorted()` mit geerbter Policy. |
| `relativePath()` | Zentrale relative Pfad-Assertion beziehungsweise `withSubPath()` im gebundenen Kontext. |
| `is_link()`-Schleifen für Quellen, Eltern und Ziele | `followSymlinks: false` am jeweiligen Root. |
| Dateiziel-Typ-/Elternprüfung in `apply()` | `assertFileTarget()`; Kollisionen innerhalb des noch ungeschriebenen Gesamtplans bleiben Schiller-Aufgabe. |
| Regex plus `yaml_parse()` / `yaml_emit()` | Front-Matter-Snapshot und `render()` aus § 9. |
| pauschales Catch-and-Re-throw | Entfernen, Phore-Exception durchreichen. |

Es bleiben die fachlichen Abläufe Auswahl, Referenzauflösung, `_root`-/Document-Root-Zuordnung, Kollisionsplanung und Anwenden. `tpl:/` sowie `./` sind Schiller-Syntax und keine neue Filesystem-Policy. Generische Dateisystem-Helper werden weder in eine andere private Schiller-Klasse verschoben noch nochmals als öffentliche Schiller-Helper verpackt. [neu]

Im begleitenden Schiller-Cleanup werden zunächst die beiden Wrapper `readFile()` und `directory()` sowie pauschale Catch-Blöcke entfernt. `walk()`, relative Pfadvalidierung, Link-Prüfungen und die bisherige Front-Matter-Planung bleiben bis zur Phore-Implementierung erhalten. Ihr ersatzloses Entfernen oder ein Wechsel zum heutigen unbeschränkten rekursiven Walk wäre keine vollständige Migration. [neu]

## § 11 Umsetzung in Phore und Fehlervertrag

Nach Freigabe des API-Vertrags betrifft die Implementierung `src/functions.php`, `src/PhoreUri.php`, `src/PhoreFile.php`, `src/PhoreDirectory.php`, `src/FrontMatterFile.php`, `src/FileStream.php` und `src/GzFileStream.php` sowie neue zentrale Options-/Policy-Typen. Alle I/O-Einstiege werden gegen denselben Kontext geprüft; Serializer und Traversierung verwenden dieselben Bausteine. Nicht ein privater Policy-Helper pro Consumer und nicht eine generische frei programmierbare Regel-DSL. [neu]

Bestehende Exception-Typen bleiben nutzbar: fehlende Datei, Zugriffsfehler, Parsing-Fehler und `PathOutOfBoundsException`. Vorgeschlagen werden spezialisierte `SymlinkNotAllowedException` und `UnsupportedFilesystemPolicyException` unter der bestehenden Filesystem-Exception-Hierarchie. Meldungen enthalten Operation, betroffenen Pfad und Ursache. Native Fehler werden an der Phore-Grenze einmal in diesen Vertrag übersetzt; bereits passende Phore-Exceptions werden nicht erneut sinnlos verpackt. Vorhandene Fehler-Wrapper auch innerhalb von Phore sind im Implementierungs-Diff entsprechend zu prüfen. [neu]

Die neue API wird erst dann in Schiller eingebaut, wenn eine konkret verfügbare Phore-Version sie enthält. Dieser Entwurfs-PR erhöht keine Abhängigkeitsversion, publiziert keine Version und behauptet keine bereits implementierte Policy. [neu]

## § 12 Abnahmekriterien und Quellen

| Fall | Erwartung vor Freigabe der Implementierung |
|---|---|
| Datei fehlt / nicht lesbar / YAML kaputt | Kontextreiche Phore-Exception ohne Consumer-Wrapper. |
| `asDirectory()` auf neuem Pfad | Keine Anlage, keine Existenzbehauptung. |
| Assert auf falschen Typ | Exception; `assertFileTarget()` legt nichts an. |
| Link am Blatt, an Eltern oder Root; auch kaputter Link | No-Follow lehnt vor I/O bzw. Rekursion ab. |
| Erlaubter interner Link / externer Link | Mit Follow nur intern erlaubt; Root-Präfix-Falle abgelehnt. |
| Neue Datei unter verlinktem Elternverzeichnis | Root-/Link-Policy wird vor Anlage durchgesetzt. |
| `..`, Scheme, NUL, absoluter Child-Pfad, Link plus `..` | Keine Umgehung über Normalisierung. |
| `as*`, `assert*`, Eltern, `rel`, Clones, Factory aus Objekt | Policy und absolute Bindung bleiben erhalten; kein CWD-Ausbruch. |
| Walk, Filter, Callback, Generator, Stream | Kein stiller Kontextverlust, keine Links vor der Prüfung betreten. |
| Schreiben, Kopieren, Rename, Löschen, Metadaten | Quelle und Ziel vollständig geprüft; unsupported Operationen fail-closed. |
| Gleichzeitiger Link-Austausch / nicht unterstütztes Backend | Atomic-Modus verhindert den Zugriff oder lehnt vorab ab; kein Fallback. |
| Hardlink-Datei / normale Verzeichnis-Linkzahl | Optionale Ablehnung regulärer Hardlinks ohne Verzeichnis-Fehlalarm. |
| Header fehlt / Header kaputt / Header unverändert | Optional `null` / Parsing-Exception / bytegleicher Snapshot. |
| Zwei Plan-Ziele `a` und `a/b` | Schiller meldet die Kollision vor dem ersten Schreiben. |
| Vorhandene Beispiele ohne Options | Bisherige API und Defaults bleiben verwendbar. |

Die Matrix ist ein Prüfauftrag für die spätere Implementierung, kein Bericht bereits bestandener Sicherheitstests. [neu]

Bestandsabgleich: [PhoreUri](../src/PhoreUri.php), [PhoreDirectory](../src/PhoreDirectory.php), [PhoreFile](../src/PhoreFile.php), [Factories](../src/functions.php) und [SchillerAutomation](https://github.com/leuffen/leuffen-shiller-lib/blob/main/src/Automation/SchillerAutomation.php). Plattformgrundlagen: [PHP realpath](https://www.php.net/manual/en/function.realpath.php), [Linux openat2](https://man7.org/linux/man-pages/man2/openat2.2.html) und [Linux link](https://man7.org/linux/man-pages/man2/link.2.html). [neu]
