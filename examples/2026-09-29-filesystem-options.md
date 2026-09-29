# Dateisystemzugriff ohne Consumer-Wrapper

| Datum | Benutzername | Kurzbeschreibung |
|---|---|---|
| 2026-09-29 | dermatthes | §§ 1–12: Direkte Operationen, vererbte Options, Assertions und Schiller-Migration entworfen |
| 2026-09-29 | dermatthes | §§ 2, 4–7, 11–12: Assoc-Array als Standard, PHPDoc-Shape, No-Follow-Default und Policy-Vererbung präzisiert |
| 2026-09-29 | dermatthes | §§ 1–6, 8–9, 11–12: Sicherheitsgrenzen, monotone Vererbung, Root-Verlust, Fail-closed-Verhalten, PHPDoc-Warnungen und prüfbare Abnahmekriterien konkretisiert |

## § 1 Normalfall: Operation aufrufen, Fehler durchreichen

**Status: API-Entwurf, keine implementierte Sicherheitsfunktion.** Die drei PHP-Beispiele neben dieser Datei verwenden ausschließlich vorhandene APIs. Hier ist § 1 ebenfalls Bestand; die Options, zusätzlichen Assertions und Front-Matter-Erweiterungen ab § 2 sind Vorschläge. Die folgenden Markdown-Codeblöcke sind Anwendungsausschnitte nach Composer-Autoloading, keine vollständigen PHP-Dateien.

Gemeinsamer Beispielkontext: `/srv/site/docs` ist der Document Root, `/srv/site/theme/_tpl` die Vorlagenwurzel. `_tpl/_root/docs/index.md` enthält `# Start` mit abschließendem Zeilenumbruch. `docs/.shiller.yml` enthält `template_dir: ../theme/_tpl`. Alle genannten Quellen existieren, soweit ein Fehlerfall nicht ausdrücklich anderes beschreibt.

```php
$content = phore_file('/srv/site/theme/_tpl/_root/docs/index.md')->get_contents();
phore_file('/srv/site/docs/index.md')->mkdir()->set_contents($content);
// Ergebnis: docs/index.md enthaelt exakt "# Start\n".

$config = phore_file('/srv/site/docs/.shiller.yml')->get_yaml();
// Ergebnis: ['template_dir' => '../theme/_tpl']; kein eigenes YAML-Parsing.
```

Keine vorgeschalteten `file_exists()`-/`is_readable()`-Guards, keine privaten `readFile()`-Helper, kein `try/catch` und keine neue allgemeine `RuntimeException` um Phore herum. Fehlender Pfad, fehlende Rechte und ungültiges YAML werden an der zuständigen Dateioperation diagnostiziert. Abfangen nur für eine echte Behandlung, etwa einen vereinbarten Fallback oder Retry; bloßes Umbenennen der Fehlermeldung ist keine Behandlung. Die äußere Anwendung entscheidet über die Darstellung gegenüber ihrem Nutzer, insbesondere über sensible Pfade in öffentlichen HTTP-Antworten.

### § 1.1 Was dieser Sicherheitsvertrag schützt

Die Anwendung ist für den vertrauenswürdigen Einstieg verantwortlich: Sie wählt Root und Options, der Consumer erhält daraus abgeleitete Phore-Objekte. `fromAssoc()` prüft die Form einer Konfiguration, nicht deren Autorisierung. Ein gültiges `['rootDir' => '/']` aus einem fremden Template ist deshalb keine erlaubte Erweiterung der Zugriffsrechte. Sicherheitskonfiguration darf nicht ungeprüft aus den zu verarbeitenden Dateien übernommen werden. [neu]

Zwei Risiken sind getrennt zu behandeln: **Kontextverlust im Programm** soll durch unveränderliche Restrictions, kontrollierte Ableitung und negative Tests abgefangen werden; **gleichzeitige Änderungen am Dateisystem** benötigen den passenden atomaren Backend-Vertrag aus § 6. Mehr `realpath()`-Prüfungen allein schließen das zweite Risiko nicht. Die Library ist weder eine Sandbox gegen beliebigen PHP-Code im selben Prozess noch eine Garantie für die Vertrauenswürdigkeit gelesener Inhalte. [neu]

## § 2 Zugriffspolitik einmal am Einstieg binden

Die öffentliche API akzeptiert für Options wahlweise ein Assoc-Array, ein `Phore\FileSystem\FilesystemOptions`-Objekt oder `null`. Der normale kurze Einstieg ist das Assoc-Array direkt an `phore_uri()`, `phore_file()` oder `phore_dir()`; intern wird ausschließlich mit validierten Options und einem gebundenen Zugriffskontext gearbeitet. **Vor der Default-Ergänzung muss feststehen, ob ein neuer Einstieg aus einem String oder eine Ableitung aus einem Phore-Objekt vorliegt.** Direkte öffentliche Konstruktoren haben denselben Vertrag und müssen Phore-Objekte vor jeder String-Konvertierung erkennen. [geändert]

Der normale Zugriff mit lokaler Policy sieht so aus:

```php
$docs = phore_dir('/srv/site/docs', options: [
    'rootDir' => '/srv/site/docs',
])->assertDirectory();

$config = $docs->withSubPath('.shiller.yml')->asFile()->get_yaml();
// Link- und Root-Regeln gelten unverändert fuer die abgeleitete Datei.
```

Für einen einzelnen Zugriff wird das Assoc-Array ebenfalls direkt angegeben:

```php
$config = phore_file('/srv/site/docs/.shiller.yml', options: [
    'rootDir' => '/srv/site/docs',
])->get_yaml();
// followSymlinks ist standardmaessig false.
```

Ein `FilesystemOptions`-Objekt ist nur die Variante für zentral hinterlegte oder mehrfach wiederverwendete Konfiguration; es ist kein notwendiger Boilerplate-Schritt vor einem normalen Zugriff:

```php
use Phore\FileSystem\FilesystemOptions;

$filesystemOptions = FilesystemOptions::fromAssoc([
    'rootDir' => '/srv/site/docs',
]);

$docs = phore_dir('/srv/site/docs', options: $filesystemOptions)->assertDirectory();
$config = phore_file('/srv/site/docs/.shiller.yml', options: $filesystemOptions)->get_yaml();
```

`FilesystemOptions::fromAssoc()` liefert einen vollständigen, unveränderlichen Options-Snapshot. Es validiert unbekannte Keys, exakte Werttypen und unzulässige Kombinationen, bevor Defaults ergänzt werden. Beispielsweise sind `rootPath`, `RootDir`, `followSymlinks => 'false'`, `0`, `1` und `null` anstelle eines Bool-Wertes ungültig; es gibt keine stillen Aliase, Casts oder Reparaturen. `rootDir` darf `null`, aber kein leerer String, NUL-haltiger Pfad oder Stream-URI sein. `requireAtomicContainment: true` verlangt eine gesetzte Root. `from()` gibt ein Options-Objekt unverändert zurück, delegiert Arrays an `fromAssoc()` und erzeugt für `null` einen Default-Snapshot; **dieser letzte Fall ist kein zulässiger Ersatz für geerbte Options**. [geändert]

Relative Root-Angaben erhalten bereits bei der Erzeugung des Options-Snapshots eine feste Arbeitsverzeichnis-Basis; ihre ursprünglichen Segmente bleiben für die spätere Link-Prüfung erhalten. Beim Binden prüft die Library den existierenden Root und bindet dessen Identität. Wiederverwendung eines Options-Objekts nach `chdir()` darf seine Root nicht neu interpretieren. Options beschreiben die Konfiguration; nur ein gebundener Phore-Kontext trägt zusätzlich die vorhandene Autorisierung und Backend-Bindung. [neu]

Zulässige Assoc-Keys des ersten Entwurfs sind:

| Key | Typ | Default beim Neueinstieg | Bedeutung und Sicherheitsgrenze |
|---|---|---|---|
| `rootDir` | `string` oder `null` | `null` | Begrenzung auf den gebundenen Verzeichnisbaum. `null` bedeutet **keine Root-Grenze**; nachträglicher Verlust oder Reset wäre eine Rechteausweitung. |
| `followSymlinks` | `bool` | `false` | Verbot im Blatt, in Eltern und beim Walk. `true` erlaubt nur unter den übrigen Restrictions unterstützte Link-Auflösungen und hebt eine Root-Grenze nicht auf. |
| `allowHardLinks` | `bool` | `true` | `false` lehnt reguläre Dateien mit mehreren Links ab. `true` garantiert keine exklusive Datenzugehörigkeit zur Root; auch `false` ist keine dauerhafte Hardlink-Isolation, siehe § 6. |
| `requireAtomicContainment` | `bool` | `false` | `true` verlangt ein für die konkrete Operation geeignetes Backend; ohne dieses Exception. `false` bietet **keinen Schutz gegen konkurrierenden Pfadaustausch**. |

Die Tabelle ist zugleich die vollständige Key-Liste; Bedeutung, Defaults und Risiken gehören in die PHPDocs aller Options-Einstiege. [geändert]

### § 2.1 PHPDoc muss Risiken am Aufruf sichtbar machen

Alle öffentlichen Options-Einstiege dokumentieren den vollständigen Array-Shape direkt: `phore_uri()`, `phore_file()`, `phore_dir()`, entsprechende öffentliche Konstruktoren und `FilesystemOptions::fromAssoc()`/`from()`. Bei `fromAssoc()` ist der Parameter ausschließlich das Array, nicht die gesamte Union. Ein reiner Verweis auf eine externe Options-Seite genügt nicht. Vorgesehener PHPDoc-Ausschnitt für `phore_file()`: [geändert]

```php
/**
 * Erstellt eine Datei-Sicht; liest keine Nutzdaten und legt keine Datei an.
 *
 * Sicherheit: Bei einem Phore-Objekt bleiben alle Restrictions erhalten.
 * options=null und [] bedeuten dann Vererbung, nicht Default-Reset.
 * Ein String ist ein neuer Einstieg ohne geerbte Autorisierung.
 *
 * rootDir: Default null nur beim Neueinstieg; dann keine Root-Grenze.
 * Eine geerbte Root darf weder entfernt noch erweitert werden.
 * followSymlinks: Default false; true erlaubt Links, nicht den Root-Ausbruch.
 * allowHardLinks: Default true; keine Garantie exklusiver Datenzugehoerigkeit.
 * requireAtomicContainment: Default false; kein Schutz vor Pfad-Races.
 * Bei true muss die gesamte Operation abgesichert sein, sonst Exception.
 *
 * @param string|\Phore\FileSystem\PhoreUri $filename Pfad oder gebundenes Objekt.
 * @param array{
 *   rootDir?: string|null,
 *   followSymlinks?: bool,
 *   allowHardLinks?: bool,
 *   requireAtomicContainment?: bool
 * }|\Phore\FileSystem\FilesystemOptions|null $options
 *     Array: nur angegebene Keys ueberschreiben geerbte Werte.
 *     Options-Objekt: vollstaendiger Snapshot, einschliesslich seiner Defaults.
 * @return \Phore\FileSystem\PhoreFile Sicht mit erhaltenem Zugriffskontext.
 * @throws \Phore\FileSystem\Exception\InvalidFilesystemOptionsException Bei ungueltiger Konfiguration.
 * @throws \Phore\FileSystem\Exception\FilesystemPolicyViolationException Bei Lockerung oder Kontextverlust.
 * @throws \Phore\FileSystem\Exception\FilesystemException Bei fehlgeschlagener Root-Bindung.
 * @see \Phore\FileSystem\FilesystemOptions::fromAssoc()
 * @example phore_file('/srv/site/docs/.shiller.yml', ['rootDir' => '/srv/site/docs'])->get_yaml();
 */
```

Die genannten neuen Exception-Typen sind Bestandteil des Entwurfs (§ 11). `asFile()`, `asDirectory()`, `getDirname()`, Ableitungen und Stream-Rückgaben dokumentieren die **Erhaltung des Kontexts**, nicht einen nicht vorhandenen Options-Parameter. `__toString()`, `getUri()`, relative Pfadstrings und Ressourcenexporte erhalten dagegen ausdrücklich einen Hinweis auf den Verlust beziehungsweise das Verlassen der kontrollierten API. Array-Shapes helfen bei statischer Analyse; sie ersetzen keine Laufzeitvalidierung. Grundlage der Shape-Syntax: [PHPStan PHPDoc Types](https://phpstan.org/writing-php-code/phpdoc-types#array-shapes). [neu]

### § 2.2 Fehlende Werte sind nicht dasselbe wie ein Reset

| Eingabe | Neuer Einstieg aus vertrauenswürdigem String | Ableitung aus gebundenem Phore-Objekt |
|---|---|---|
| Options weggelassen oder `options: null` | Defaults erzeugen. | Gesamten Kontext unverändert übernehmen. |
| `options: []` | Defaults erzeugen. | Keine Änderung; insbesondere Root und Atomic-Anforderung behalten. |
| Partielles Assoc-Array | Fehlende Keys mit Defaults ergänzen. | Nur ausdrücklich vorhandene Keys prüfen und anwenden; fehlende Keys erben. |
| `['rootDir' => null]` | Bewusst keine Root-Grenze. | Bei bestehender Root als Lockerung ablehnen. |
| `['followSymlinks' => null]` | Ungültiger Wert. | Ungültiger Wert, nicht „erben“ oder „Default“. |
| `FilesystemOptions`-Objekt | Vollständigen validierten Snapshot binden. | Jeden Wert, auch Defaults, auf zulässige Einschränkung prüfen. Niemals den bisherigen Kontext ersetzen. |

Diese Unterscheidung muss im zentralen Normalisierungs-/Vererbungsweg liegen. Ein partielles Array darf nicht erst über `fromAssoc()` mit globalen Defaults gefüllt und anschließend auf den Parent kopiert werden. Vorhandene Keys sind vor Null-Coalescing oder `isset()`-Logik zu unterscheiden. Ein Options-Snapshot aus `fromAssoc([])` ist bei einer bereits begrenzten Datei deshalb **kein** leerer Patch: Sein `rootDir: null` muss zur Exception führen. [neu]

Die Defaults bleiben `rootDir: null`, `followSymlinks: false`, `allowHardLinks: true`, `requireAtomicContainment: false`. Für neue Aufrufe ohne Options ist das Link-Verbot eine bewusste Verhaltensänderung gegenüber der heutigen Implementierung, keine unveränderte Abwärtskompatibilität. Beim Ableiten gelten hingegen die geerbten Werte: Ein zuvor ausdrücklich erlaubtes `followSymlinks: true` wird nicht beim nächsten Cast heimlich auf `false` zurückgesetzt. [geändert]

## § 3 Typwechsel und Voraussetzungen unterscheiden

| Aufruf | Vertrag |
|---|---|
| `asFile()` / `asDirectory()` | Vorhanden: nur typisierte Sicht; keine Existenzgarantie, keine Anlage. |
| `assertFile()` / `assertDirectory()` | Vorhanden: Existenz und Typ verlangen; mit `true` darf angelegt werden. |
| `assertReadable()` / `assertWritable()` | Vorhanden: eine eigenständig benötigte Zugriffs-Voraussetzung verlangen. |
| `assertNoSymlinks()` | Neu: bestehenden Pfadanteil einschließlich Eltern prüfen und ein typgleiches Objekt mit dauerhaft verschärfter No-Follow-Policy zurückgeben; fehlende Blattdatei allein ist kein Link-Fehler. |
| `assertRelativePath()` | Neu: nichtleeren relativen lokalen Pfad verlangen; NUL, absolute Pfade, Schemes, Backslashes und `..` oberhalb des Ausgangspunkts ablehnen. Keine Dateianlage. |
| `assertFileTarget()` | Neu: Dateiziel vorab verlangen, ohne es anzulegen; vorhandenes Blatt muss eine schreibbare Datei sein, vorhandene Eltern müssen Verzeichnisse sein, fehlende Eltern müssen unter der Policy anlegbar sein. |

Die letzten drei Zeilen sind Entwurf. `assertNoSymlinks()` liefert absichtlich ein neues eingeschränktes Objekt: Die Rückgabe verwenden; bereits vorher erzeugte Objekte werden nicht nachträglich geändert. Im Standardzugriff genügt die Policy am Einstieg. Zusätzliche Assertions sind für tatsächliche Vorabbedingungen, nicht als Pflicht-Kette vor jedem Lesen gedacht. Auch alle bestehenden Assert-Methoden müssen die gebundene Policy respektieren und weiterreichen.

Unabhängige Variante: Ein ausdrücklich gelockertes Quellobjekt wird für einen folgenden Zugriff eingeschränkt. Beim normalen No-Follow-Default wäre die zusätzliche Assertion redundant. [geändert]

```php
$source = phore_file('/srv/site/docs/index.md', options: [
    'followSymlinks' => true,
]);
$strictSource = $source->assertNoSymlinks();
$content = $strictSource->get_contents();
// $source bleibt unveraendert; nur $strictSource verbietet jetzt Links.
```

Normale boolesche Abfragen bleiben für fachliche Auswahl erlaubt, etwa Dateien statt Verzeichnisse auswählen oder eine optionale Konfiguration verwenden. Ein Policy-Verstoß darf dabei nicht stillschweigend als `false` beziehungsweise „Datei fehlt“ verschwinden. PHPs abschaltbares `assert()` ist nur eine Darstellung erwarteter Beispielwerte, niemals Ersatz für produktive `assert*()`-Prüfungen.

## § 4 Vererbung darf an keiner Ebene abbrechen

Der gebundene Zugriffskontext bleibt bei `asFile()`, `asDirectory()`, allen `assert*()`-Methoden, `abs`, `clean`, `rel`, `join`, `join_secure`, `withSubPath`, `withRelativePath`, `withFileName`, `withFileExtension`, Eltern-/Verzeichniszugriffen, Kopien und Clones erhalten. Auch `genWalk`, `walk`, `walkR`, `list`, `listFiles` und `getListSorted` geben ihn an Aufrufer und Callbacks weiter. Ein Wechsel der Pfaddarstellung darf den intern absolut gebundenen Zugriffspfad nicht entkoppeln. `rel($rootPath)` verändert nur die Darstellung; dieser bestehende Methodenparameter ist **kein Setter für `options.rootDir`**. [geändert]

### § 4.1 Monotone Restrictions statt ersetzbarer Konfiguration

**Invariante: Ein abgeleitetes Objekt darf nur dieselben oder weniger Zugriffe zulassen als sein Parent.** Formal gilt für die durch die Policy erlaubten Operationen `Allowed(child) ⊆ Allowed(parent)`. Das gilt unabhängig davon, ob die Options ursprünglich aus einem Array oder Objekt stammten. Ein Typwechsel allein übernimmt exakt denselben Kontext. [neu]

| Restriction | Erlaubte Änderung bei expliziter Ableitung | Verbotene Änderung / Sicherheitsproblem |
|---|---|---|
| `rootDir` und gebundene Root-Identität | Gleiche Root; oder nachweislich darin liegende engere Root, die auch den aktuellen Zugriffspfad enthält. Alle bisherigen Root-Grenzen bleiben wirksam. | Root entfernen, Eltern-/Geschwisterverzeichnis einsetzen, Identität unbemerkt neu binden oder einen nicht beweisbaren Teilbaum akzeptieren. Dadurch könnten andere Dateien erreichbar werden. |
| `followSymlinks` | `true` nach `false`; unverändert lassen. | `false` nach `true`: zuvor verbotene Link-Auflösung würde möglich. |
| `allowHardLinks` | `true` nach `false`; unverändert lassen. | `false` nach `true`: zuvor abgelehnte Mehrfachverlinkungen würden akzeptiert. |
| `requireAtomicContainment` | `false` nach `true`, sofern ein geeignetes Backend bereitsteht; unverändert lassen. | `true` nach `false` oder stiller Backend-Fallback: Schutz vor Pfad-Races würde entfallen. |

Eine angeforderte Lockerung wirft eine `FilesystemPolicyViolationException`; sie wird weder still erlaubt noch still auf den alten Wert geklemmt. Eine Root, deren Gleichheit oder Unterordnung nicht sicher feststeht, ist nicht zulässig. Auch eine strengere Root wird abgelehnt, wenn der aktuelle Objektpfad außerhalb läge. Zusätzliche Einschränkungen ändern nicht rückwirkend vorhandene Parent-/Sibling-Objekte. [neu]

### § 4.2 Typwechsel ohne erneute Options-Übergabe

Dieser Ausschnitt ergänzt `$docs` aus § 2. `phore_dir($file)` und `asDirectory()` sind nur eine andere Sicht auf **denselben Pfad**, nicht dessen Elternverzeichnis; dafür wird ausdrücklich `getDirname()` verwendet. Keine der Konvertierungen darf den Root oder die übrigen Restrictions zurücksetzen. [neu]

```php
$source = phore_file($docs->withSubPath('.shiller.yml'));
$again = phore_file($source);
$uri = phore_uri($again);
$directoryView = phore_dir($uri);
$roundtrip = phore_file($directoryView);
$config = $roundtrip->get_yaml();

$configViaMethods = $source->asDirectory()->asFile()->get_yaml();
$parent = phore_dir($source->getDirname())->assertDirectory();
$configFromParent = $parent->withSubPath('.shiller.yml')->asFile()->get_yaml();
// Alle drei Ergebnisse: ['template_dir' => '../theme/_tpl']; gleiche Restrictions.
```

Die folgenden zwei unabhängigen Fehlerfälle verwenden jeweils `$source` aus diesem Ausschnitt. Sie brechen bereits bei der Ableitung ab; kein Catch und kein anschließender Zugriff mit Defaults. [neu]

```php
phore_file($source, options: ['rootDir' => null]);
// FilesystemPolicyViolationException: eine bestehende Root darf nicht entfallen.
```

```php
phore_file($source, options: ['followSymlinks' => true]);
// FilesystemPolicyViolationException: das geerbte Link-Verbot darf nicht entfallen.
```

### § 4.3 Kontextverlust konstruktiv erschweren und prüfen

Intern trägt jedes Phore-Objekt einen verpflichtenden, unveränderlichen Zugriffskontext: effektive Options, absolut gebundene Pfadbasis, Root-Anker und Backend-/Herkunftsinformation. Ein bewusst unbeschränkter Neueinstieg ist ein gültiger Kontext, **nicht** die Abwesenheit eines Kontexts. Ein fehlender oder inkonsistenter Kontext führt vor Nutzdaten-I/O zur Exception; kein `context ?? new DefaultContext()`. [neu]

Neueinstieg und Ableitung müssen verschiedene interne Wege sein. Die Ableitung benötigt zwingend den Parent-Kontext und übernimmt ihn unverändert oder erzeugt eine nachweislich strengere Version. Ein zentraler interner `assert*()`-Prüfweg kontrolliert Kontextintegrität und Restrictions vor der Operation; ein zweiter Abgleich an der Ausgabegrenze prüft, dass neue Objekte oder Stream-Rückgaben den geforderten Kontext besitzen. Der Consumer implementiert dafür keine eigenen Guards. [neu]

Factories und öffentliche Konstruktoren benötigen einen objektfähigen Pfadparameter wie `string|PhoreUri`; eine vorherige implizite oder explizite Konvertierung nach `string` zerstört die Herkunftsinformation. Öffentliche Options dürfen nicht mutierbar geteilt werden. Unbekannte Fremdobjekte werden nicht durch beliebiges `__toString()` zu autorisierten Phore-Objekten; Transport und Deserialisierung dürfen keinen fehlenden Kontext als Default rekonstruieren. Die erste Policy-Version unterstützt keine Deserialisierung gebundener Pfad-/Stream-Kontexte und lehnt diese ausdrücklich ab. [neu]

Ein Runtime-Check allein kann nicht jeden Programmierfehler erkennen, der absichtlich oder versehentlich einen neuen, formal gültigen Kontext erzeugt. Deshalb sind der kontrollierte Erzeugungsweg, das Verbot interner String-Roundtrips, API-Inventar und Mutationstests aus § 12 gemeinsam erforderlich. Insbesondere darf ein Test nicht nur denselben fehlerhaften Vererbungshelper nochmals als Erwartungswert aufrufen. [neu]

### § 4.4 Strings, Streams und mehrere Pfadargumente

**Sicherheitsgrenze String:** `(string) $file`, `getUri()` und relative Pfadstrings tragen keine Restrictions. `phore_file((string) $boundedFile)` kann nicht erkennen, dass der String früher aus einem begrenzten Objekt stammte. Ein interner solcher Roundtrip ist deshalb ein Sicherheitsfehler; außerhalb der Library liegt die erneute Autorisierung beim Aufrufer. Dass der neue Zugriff weiterhin `followSymlinks=false` hätte, stellt den verlorenen Root nicht wieder her. [geändert]

`fopen()`, `gzopen()`, `FileStream::getFileObject()` und `fclose()` erhalten den gebundenen Kontext. Ein Stream wird aus dem bereits autorisierten Handle aufgebaut, nicht durch erneutes Öffnen seines Pfadstrings. Für Operationen auf einem bestehenden Handle gilt dessen beim Erwerb erteilte Autorisierung; ein nachträglicher Namewechsel ist keine automatische Revocation (§ 6). Rohe Ressourcenexporte wie `detach()`/`getRessource()` dürfen nicht als weiterhin kontrollierte Phore-Zugriffe dargestellt werden. Für Root-begrenzte oder atomar gebundene Streams lehnt die erste Policy-Version solche Exporte ab, solange ihr Vertrag außerhalb der Library nicht erzwingbar ist. [geändert]

Bei Kopieren und Verschieben werden Quellen- und Zielkontext **getrennt** geprüft. Ein ausdrücklich übergebenes Zielobjekt darf einen eigenen, von der Anwendung autorisierten Root haben; es ersetzt niemals die Quellen-Policy. Ein nackter Zielstring innerhalb einer Operation ist hingegen keine neue unbeschränkte Autorisierung: Er erbt den Kontext des Receivers und muss darin zulässig sein. Bei Rename/Move bleibt die Policy des Rückgabeobjekts erhalten; ältere Aliase werden nicht auf einen unbeschränkten Zustand umgeschrieben. [neu]

## § 5 Symlinks und Root-Grenzen

`followSymlinks: false` ist der Default und bedeutet **Fehler statt Überspringen**: Links am Blatt, in Elternverzeichnissen, an der Root oder in ihrem angegebenen Zugangspfad sind verboten. Beim Walk wird vor jeder Rekursion und vor jedem Dateizugriff geprüft, auch bevor ein Dateinamenfilter angewendet wird. Kaputte Links und Link-Schleifen sind ebenfalls Fehler. Diese Semantik passt zu Schillers bisheriger Ablehnung verlinkter Vorlagen.

Die folgende unabhängige Variante erlaubt Links, aber nur innerhalb der Root. `docs/current.yml` zeige dabei auf `docs/config/production.yml`:

```php
$linkedDocs = phore_dir('/srv/site/docs', options: [
    'rootDir' => '/srv/site/docs',
    'followSymlinks' => true,
])->assertDirectory();
$config = $linkedDocs->withSubPath('current.yml')->asFile()->get_yaml();
```

Das aufgelöste Ziel muss innerhalb der Root bleiben. `/srv/site/docs-backup` ist kein Kind von `/srv/site/docs`; ein einfacher String-Präfixvergleich genügt nicht. Absolute Einstiegspfade sind zulässig, absolute **Child-Pfade** und Ausbrüche über `..` oder Schemes nicht. Die ursprüngliche Segmentfolge bleibt bis zur sicheren Auflösung erhalten: `link/../datei` darf nicht zuerst textuell verkürzt werden und dadurch eine andere Datei bezeichnen oder die Link-Prüfung umgehen. `withSubPath()` nutzt intern dieselbe relative Pfad-Assertion. Auch `withRelativePath()`, `withFileName()` und Elternzugriffe sind keine Ausnahmen von der Root-Grenze. [geändert]

Neue Ziele verlangen die Prüfung des nächsten vorhandenen Elternverzeichnisses und jedes danach angelegten Segments; nur `realpath($ziel)` reicht nicht, weil PHP bei nicht vorhandenen Pfaden `false` liefert. Die Pfadgrenze gilt bei Lesen, Schreiben, Anlegen, Umbenennen, Kopieren, Löschen und Metadatenänderungen gleichermaßen. Plattformen mit nicht zuverlässig prüfbaren Junctions/Reparse-Points müssen begrenzte Zugriffe ablehnen statt einen nicht belegten Schutz zu versprechen. Grundlage: [PHP realpath](https://www.php.net/manual/en/function.realpath.php).

**Nicht bestimmbar ist nicht erlaubt.** Fehlende Rechte auf einem Elternverzeichnis, nicht auflösbare Root-Identität, Link-Schleifen, unbekannte Dateitypen und nicht unterstützte Auflösungsregeln führen zur Exception. `false` aus einem Betriebssystemaufruf darf nicht pauschal als „Datei fehlt, darf angelegt werden“ interpretiert werden. Ein sicher festgestelltes fehlendes Blatt ist dagegen für `assertFileTarget()` ein normaler Fall. [neu]

Die erste Policy-Version beschränkt Nutzdatenzugriffe auf lokale reguläre Dateien und Verzeichnisse. Ungeprüfte Stream-Wrapper einschließlich `file://`, `php://`, `phar://` sowie FIFO-/Socket-/Device-Zugriffe werden nicht als normale Datei behandelt. ZIP-Import, Gzip-Wiederöffnung oder Subprozesse dürfen nur eingesetzt werden, wenn alle Pfade und Seiteneffekte denselben Vertrag erfüllen; andernfalls vorher `UnsupportedFilesystemPolicyException`. Für Windows sind insbesondere UNC-/Drive-relative Pfade, alternative Datenströme, Groß-/Kleinschreibung und Reparse-Points eigene Backend-Fälle, keine POSIX-String-Rezepte. [neu]

## § 6 Gleichzeitige Änderungen und Hardlinks ehrlich abgrenzen

### § 6.1 Atomare Absicherung gilt pro konkreter Operation

**`rootDir` plus `requireAtomicContainment=false` ist keine belastbare Sicherheitsgrenze gegen einen Akteur, der den Baum gleichzeitig verändern kann.** Dieser Modus ist nur für kontrollierte Verzeichnisbäume vorgesehen. Für fremdschreibbare Bäume muss die Anwendung `requireAtomicContainment: true` verlangen. Die Library kann die Abwesenheit konkurrierender Angreifer nicht durch eine zusätzliche Vorabprüfung feststellen. [geändert]

Prüfen mit `realpath()`/`lstat()` und anschließendes Öffnen über einen Pfad sind getrennte Schritte. Auch PHPs Stat-/Realpath-Caches müssen berücksichtigt werden; ihr Leeren macht daraus keinen atomaren Zugriff. Ein atomares Backend muss Root-Bindung und Auflösung an den tatsächlichen Zugriff koppeln, statt nach erfolgreicher Prüfung denselben String noch einmal zu öffnen. Quellen: [PHP clearstatcache](https://www.php.net/manual/en/function.clearstatcache.php), [Linux open](https://man7.org/linux/man-pages/man2/open.2.html). [geändert]

Für die Linux-Auflösung sind `openat2()`-Regeln wie `RESOLVE_BENEATH`, `RESOLVE_NO_SYMLINKS` und `RESOLVE_NO_MAGICLINKS` relevant. `O_NOFOLLOW` allein betrifft nur die letzte Komponente. Mount-Wechsel einschließlich Bind-Mounts erfordern eine gesonderte Beschränkung wie `RESOLVE_NO_XDEV`; die erste atomare Policy-Version lehnt solche Wechsel unterhalb der gebundenen Root ab. Ein Backend darf absolute Links nicht still mit einer anderen Root-Semantik umdeuten; unterstützt es deren sichere Auflösung nicht, muss es sie trotz `followSymlinks=true` ablehnen. Grundlage: [Linux openat2](https://man7.org/linux/man-pages/man2/openat2.2.html). [neu]

**Ein sicher geöffneter Parent-Handle genügt nicht als Beweis für jede spätere Operation.** Ein Verzeichnis kann zwischen dem Öffnen und einem nachfolgenden Rename/Unlink verschoben werden. `openat2()` macht nachgeschaltete Schreib-, Rename-, Delete- oder rekursive Operationen nicht automatisch sicher. Das Backend muss für jede angebotene Operation den vollständigen Vertrag nachweisen; kann es das für einen konkurrierend veränderbaren Baum nicht, wird die Operation vor mutierenden Seiteneffekten als nicht unterstützt abgelehnt. Quellen zur Handle- und Rename-Semantik: [open](https://man7.org/linux/man-pages/man2/open.2.html), [rename](https://man7.org/linux/man-pages/man2/rename.2.html). [neu]

Ein Root-Handle bindet eine Verzeichnisidentität, nicht einen beliebig neu belegbaren Namen. Nach Austausch des ursprünglichen Root-Pfads darf niemals still der Ersatzbaum autorisiert werden. Erkannte Inkonsistenz führt zum Abbruch. Bereits autorisierte offene Datei-Handles beziehen sich weiterhin auf dasselbe Objekt, auch wenn sein Name verändert wird; der Vertrag verspricht weder permanente Zugehörigkeit jedes Inodes zu einem unveränderten Pfadnamen noch automatische Revocation solcher Handles. Eine Anwendung, die diese stärkere Isolation benötigt, muss zusätzlich Namespace-/Schreibrechte oder eine Prozess-/Dateisystemgrenze kontrollieren. [neu]

Fehlt die benötigte Backend-Fähigkeit, wird `UnsupportedFilesystemPolicyException` geworfen. Bei unklarer Auflösung darf ein Backend allenfalls begrenzt mit **denselben** Sicherheitsflags erneut versuchen; danach Exception. Niemals `EAGAIN`, `ELOOP`, `EXDEV`, unbekannte Flags oder andere Fehler durch Weglassen von Restrictions „beheben“. Ein bloßes Feature-Flag oder erfolgreicher Lese-Test ist kein Nachweis für Rename/Write-Sicherheit. [neu]

### § 6.2 Hardlink-Prüfung ist keine dauerhafte Datenisolation

Hardlinks sind kein Symlink-Zielpfad: Dieselben Dateidaten können unter Namen innerhalb und außerhalb einer Root erreichbar sein. Eine Pfadgrenze allein beweist daher keine Datenherkunft oder ausschließliche Datenzugehörigkeit. `allowHardLinks: false` soll reguläre Dateien mit mehreren Links ablehnen, auch vor destruktiven Schreibzugriffen; das gilt nicht für den normalen Link-Zähler von Verzeichnissen. Selbst diese Momentaufnahme verhindert keine spätere neue Verlinkung durch andere Akteure. Für eine harte Isolationsgarantie werden zusätzlich kontrollierte Besitz-/Schreibrechte oder eine Dateisystem-/Prozessgrenze benötigt. Die Library ist keine Sandbox gegen beliebigen PHP-Code, der native Dateioperationen aufruft.

Der Link-Zähler ist am tatsächlich autorisierten Dateiobjekt zu prüfen, nicht an einem anderen, zuvor per Pfad ermittelten Objekt. Vor dieser Prüfung dürfen insbesondere weder `w`-/Truncate-Zugriffe noch Schreiben erfolgen. Auch ein atomar erworbener Handle verhindert keine spätere zusätzliche Hardlink-Anlage. `requireAtomicContainment=true` ändert diese ausdrücklich dokumentierte Grenze nicht. [neu]

Diese strengere, unabhängige Variante ist nur mit einem passenden Backend ausführbar:

```php
$isolated = phore_dir('/srv/site/docs', options: [
    'rootDir' => '/srv/site/docs',
    'allowHardLinks' => false,
    'requireAtomicContainment' => true,
])->assertDirectory();
$content = $isolated->withSubPath('index.md')->asFile()->get_contents();
```

## § 7 Traversierung direkt verwenden

Dieser neue Einstieg bindet den Template-Root, unabhängig von `$docs`:

```php
$templates = phore_dir('/srv/site/theme/_tpl', options: [
    'rootDir' => '/srv/site/theme/_tpl',
])->assertDirectory();
$files = [];
foreach ($templates->listFiles('*.md', recursive: true) as $file) {
    $files[$file->getRelPath()] = $file->get_contents();
}
// Beispielsweise: ['_root/docs/index.md' => "# Start\n", ...].
```

Keine eigene Rekursions-Queue, kein `is_link()` im Callback und keine erneute Read-Prüfung. Der Filter darf die Navigation durch Verzeichnisse nicht verhindern. Generatoren dürfen beim Iterieren Exceptions werfen; die Policy darf nicht erst nach `yield` oder nach dem Betreten eines Links geprüft werden. Bei erlaubten Links verhindert eine Erkennung bereits im aktuellen Rekursionspfad besuchter Verzeichnisidentitäten Zyklen; ein erreichtes Tiefenlimit wird gemeldet und liefert keinen still unvollständigen Plan.

Für deterministische Verarbeitung kann Schiller die vorhandene sortierte Liste verwenden und Verzeichnisse fachlich ausfiltern. Diese Auswahl ist keine Fehler-Guard-Schicht. Das vorhandene `getRelPath()` bezeichnet weiterhin den relativen Ableitungspfad des Objekts; nicht ungefragt auf den letzten Unterverzeichnis-Walk umdefinieren. Ein frisch gebundener Traversierungs-Einstieg beginnt ohne Relativpfad-Präfix.

## § 8 Neue Dateiziele ohne vorzeitiges Schreiben prüfen

Der folgende Ablauf ergänzt `$docs` aus § 2. Der gezeigte Plan enthält bereits fachlich eindeutige, relative Ziele:

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

`assertFileTarget()` erzeugt weder Elternverzeichnisse noch Platzhalterdateien und prüft auch existierende Eltern auf falschen Typ. Ein Konflikt zwischen zwei erst geplanten Pfaden, etwa `a` und `a/b`, bleibt dagegen eine Eigenschaft des gesamten Schiller-Plans und muss dort vor dem ersten Schreiben erkannt werden. Die Vorprüfung ist keine Transaktion: Spätere I/O-Fehler können zu einem teilweise geschriebenen Plan führen; jede Schreiboperation prüft ihre Voraussetzungen erneut.

Eine erfolgreiche Assertion ist kein dauerhaft gültiger Sicherheitsnachweis und darf kein späteres Überspringen der I/O-Prüfung auslösen. Verweigerte oder nicht unterstützte Operationen dürfen nicht vorher eine Zieldatei leeren, ein fremdes Ziel ersetzen oder Rechte ändern. Temporäre Dateien für begrenzte Schreiboperationen liegen innerhalb der jeweiligen Ziel-Root, werden exklusiv angelegt und erhalten keine unnötig erweiterten Rechte. Fehlgeschlagenes sicheres Rename ist kein Anlass für einen unkontrollierten Copy/Delete-Fallback. [neu]

Anlage- und Cleanup-Pfade gehören ebenfalls zum Sicherheitsvertrag: kein automatisches `chmod(0777)` als vermeintliche Reparatur fehlender Rechte, keine unbeschränkte Löschung über einen rekonstruierten String. Kann ein Cleanup-Ziel nicht sicher zugeordnet werden, unterbleibt die Löschung; eine explizite Cleanup-Operation meldet die Exception. Ein Destructor darf auch während Fehlerbehandlung keinen unsicheren Ersatzpfad benutzen. [neu]

## § 9 Front Matter einmal lesen, nicht neu implementieren

Bereits vorhanden sind `get_yaml()`, `get_front_matter()` sowie `put_front_matter()`. Für Schillers zuerst vollständig aufgebauten Schreibplan fehlen jedoch eine optionale Header-Erkennung und ein verlustfreier Snapshot mit reinem Rendern ohne Schreibzugriff. Diese Lücke nicht durch Regex-/YAML-Helper oder temporäre Dateien in Schiller schließen.

Vorschlag: `get_front_matter(?string $cast = null, bool $required = true)` liefert mit `required: false` nur bei **fehlendem** Header `null`; ein vorhandener kaputter Header bleibt eine `FileParsingException` mit Dateipfad und Position. `FrontMatterFile` erhält `sourceContents` als unveränderlichen Originaltext und `render(): string` als gemeinsamen Serializer, den auch `put_front_matter()` nutzt. Unveränderte Dokumente rendern bytegleich; erst eine echte Header-/Body-Änderung serialisiert neu. Unbekannte Headerwerte bleiben erhalten; bei neu serialisiertem YAML wird keine Erhaltung von Kommentaren/Formatierung versprochen. Der Snapshot entsteht aus genau einem Lesevorgang.

Der folgende Ausschnitt ergänzt `$templates` aus § 7; `index.raven.md` sei eine Markdown-Vorlage mit gültigem `schiller`-Block und Body `# Raven\n`. Tag-Auswahl und `schiller`-Schema sind hier bereits fachlich geprüft:

```php
$source = $templates->withSubPath('index.raven.md')->asFile();
$document = $source->get_front_matter();
$unchanged = $document->sourceContents;

$document->header['schiller']['instructions'] = ['tpl:/instructions/style.md'];
$plan = ['index.md' => $document->render()];
// Nur geplant: neuer Header plus derselbe Body, noch keine Zieldatei geschrieben.
```

Bei `.template`-Dateien kommt `$document->content` in den Plan, damit nur dort der Metadaten-Header entfällt. Markdown ohne Header kann beim Indexieren mit `required: false` übersprungen werden, ohne einen erwarteten Normalfall per Catch zu behandeln. Prüfungen für Schiller-Tags, `target` und `instructions` gehören nicht in die generische Filesystem-Library.

**Dateisystem-Containment macht den Parser nicht automatisch sicher.** PHP warnt bei nicht vertrauenswürdigem YAML vor aktivierter Deserialisierung über `!php/object`. Vor Verarbeitung solcher Vorlagen muss Phore eine sichere Parser-Konfiguration nachweisen oder abbrechen; die Prüfung erst nach `yaml_parse()` wäre zu spät. Parser-Callbacks dürfen keine ungeprüften Neben-Zugriffe auslösen, und Parserfehler oder Policy-Verstöße sind niemals „Header fehlt“. Ressourcenlimits für große Dateien, Header und Traversierung müssen im aufrufenden Betriebsprofil festgelegt werden; dieser Options-Entwurf ist keine allgemeine DoS-Abwehr. Quelle: [PHP yaml_parse](https://www.php.net/manual/en/function.yaml-parse.php). [neu]

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

Es bleiben die fachlichen Abläufe Auswahl, Referenzauflösung, `_root`-/Document-Root-Zuordnung, Kollisionsplanung und Anwenden. `tpl:/` sowie `./` sind Schiller-Syntax und keine neue Filesystem-Policy. Generische Dateisystem-Helper werden weder in eine andere private Schiller-Klasse verschoben noch nochmals als öffentliche Schiller-Helper verpackt.

Im begleitenden Schiller-Cleanup werden zunächst die beiden Wrapper `readFile()` und `directory()` sowie pauschale Catch-Blöcke entfernt. `walk()`, relative Pfadvalidierung, Link-Prüfungen und die bisherige Front-Matter-Planung bleiben bis zur Phore-Implementierung erhalten. Ihr ersatzloses Entfernen oder ein Wechsel zum heutigen unbeschränkten rekursiven Walk wäre keine vollständige Migration.

## § 11 Umsetzung in Phore und Fehlervertrag

Nach Freigabe des API-Vertrags betrifft die Implementierung `src/functions.php`, `src/PhoreUri.php`, `src/PhoreFile.php`, `src/PhoreDirectory.php`, `src/FrontMatterFile.php`, `src/FileStream.php`, `src/GzFileStream.php` und temporäre Datei-/Verzeichnisoperationen sowie zentrale Options-/Policy-Typen. Alle öffentlichen Erzeugungs- und I/O-Wege werden anhand eines API-Inventars der zentralen Kontextprüfung zugeordnet; eine nicht eingeordnete Operation erhält keine implizite Freigabe. Kein privater Policy-Helper pro Consumer und keine frei programmierbare Regel-DSL. [geändert]

Die Entscheidung ist dreistufig: **erlaubt, verboten oder nicht sicher bestimmbar**. Nur „erlaubt“ erreicht Nutzdatenzugriff oder Mutation. Die beiden anderen Fälle führen zu aussagekräftigen Phore-Exceptions; keine Warnung mit anschließendem Weiterarbeiten. Vorhandene Exception-Typen bleiben nutzbar. Folgende Zuordnung ist vorgeschlagen: [geändert]

| Fehler | Vorgesehene Exception |
|---|---|
| Unbekannter Key, falscher Typ oder unzulässige Options-Kombination | `InvalidFilesystemOptionsException` (neu, unter `FilesystemException`). |
| Root-Reset, gelockerte Restriction oder verlorener/inkonsistenter Kontext | `FilesystemPolicyViolationException` (neu, unter `FilesystemException`). |
| Ziel außerhalb der gebundenen Root | `PathOutOfBoundsException`. |
| Verbotener Symlink | `SymlinkNotAllowedException` (bereits vorgeschlagen). |
| Fehlende Backend-Fähigkeit oder nicht sicher unterstützte Operation | `UnsupportedFilesystemPolicyException` (bereits vorgeschlagen). |
| Fehlende Datei, unzureichende Rechte, Parsing-Fehler | Bestehende passende Filesystem-/Parsing-Exception. |

Meldungen nennen Operation, Pfad und verletzte Restriction beziehungsweise fehlenden Nachweis; sie müssen keine Dateiinhalte oder Geheimnisse enthalten. Native Fehler werden einmal an der verantwortlichen Phore-Grenze übersetzt. Bereits passende Phore-Exceptions werden nicht nochmals allgemein verpackt. `exists()` liefert nur bei sicher festgestelltem Fehlen `false`; Access-/Policy-/Backend-Fehler werden durchgereicht. Ein Catch darf weder eine fehlende Root noch ein ungeeignetes Backend durch einen neuen unbeschränkten Zugriff ersetzen. [neu]

Die neue API wird erst dann in Schiller eingebaut, wenn eine konkret verfügbare Phore-Version sie enthält. Dieser Entwurfs-PR erhöht keine Abhängigkeitsversion, publiziert keine Version und behauptet keine bereits implementierte Policy.

## § 12 Abnahmekriterien und Quellen

### § 12.1 Konkrete Testfälle statt einer allgemeinen Sicherheitszusage

Die folgende Matrix ist ein Prüfauftrag für die spätere Implementierung, **kein Bericht bestandener Sicherheitstests**. Testfixtures enthalten eine erlaubte Root mit Kindverzeichnis, ein ähnlich benanntes Geschwisterverzeichnis und eine außerhalb liegende Sentinel-Datei mit eindeutigem Inhalt. Links, neue Ziele und Pfadaustausch werden gezielt dagegen aufgebaut. [geändert]

| ID | Fall / Testaufbau | Erwartung |
|---|---|---|
| T01 | Frischer String-Einstieg mit fehlenden Options, `null`, `[]` und vollständigem Options-Objekt. | Dokumentierte Defaults; No-Follow aktiv. Array und Objekt ergeben bei gleichen Werten denselben Vertrag. |
| T02 | Falsche Keys einschließlich `rootPath`/`RootDir`, boolesche Strings/Zahlen/`null`, leerer Root, Schemes; Atomic ohne Root. Auch Aufrufer ohne `strict_types`. | Validierungsfehler vor Zugriff, keine Casts oder stillen Defaults. Öffentliche Konstruktoren dürfen die Validierung nicht umgehen. |
| T03 | Begrenztes Objekt erneut über jede Factory, mit fehlenden Options, `null`, `[]` und partiellem Array. | Root, erlaubtes/verbotenes Follow, Hardlink-Regel und Atomic-Anforderung bleiben jeweils erhalten; kein Auffüllen aus globalen Defaults. |
| T04 | Root nach `null`, Parent, Geschwister oder nicht prüfbarem Alias ändern; gültig engeren Root bei darin liegendem Objekt verwenden. | Lockerung/Unklarheit wirft; echte Einschränkung erlaubt. Ursprüngliches Objekt bleibt unverändert. |
| T05 | Alle Bool-Kombinationen und Änderungsrichtungen; vollständiger Default-Snapshot auf einem begrenzten Objekt. | Verbotene Lockerungen aus § 4.1 werfen. Defaults eines Options-Objekts zählen mit; `fromAssoc([])` setzt keine Root zurück. |
| T06 | Mehrstufige Ketten aus Factory-Casts, `as*`, `assert*`, `clean`, `join`, `abs`, `rel`, Eltern-/Dateinamenmethoden und `clone`. | An jedem Zwischenschritt erhaltene Restrictions; anschließend echter erlaubter Zugriff und verweigerter Ausbruch. Rückgabetyp allein reicht nicht. |
| T07 | Arbeitsverzeichnis zwischen Options-Erzeugung, Bindung, `rel()`, Cast und Zugriff ändern; Root-Pfad nach Bindung ersetzen. | Keine neue CWD-Basis, keine Autorisierung des Ersatzbaums; sichere Ablehnung bei Inkonsistenz. |
| T08 | Links im Blatt, Parent, Root-Zugang; kaputte Links, Zyklen und Link plus `..`. Bei Follow internen und externen Link vergleichen. | No-Follow verweigert jeden Link; Follow gestattet nur sicher unterstützte interne Auflösung. Keine textuelle Normalisierung als Bypass. |
| T09 | Root-Präfix-Falle, absoluter Child-Pfad, NUL, `..`, Schemes und plattformspezifische Pfadformen. | Kein Ausbruch und keine stille Umdeutung; nicht unterstützte Plattformsemantik wird abgelehnt. |
| T10 | Jeder Walk-/List-Weg, Filter, Callback und Generator; Link außerhalb des Musters und Änderung zwischen `yield` und späterem Zugriff. | Prüfung vor Rekursion/Weitergabe; Kontext bleibt erhalten. Späterer Zugriff prüft erneut; Abbruch statt still unvollständigem Ergebnis. |
| T11 | `fopen`/`gzopen`, `getFileObject`, `fclose`, Stream-Lesen/-Schreiben/-Truncate und Ressourcenexport. | Keine String-Wiederöffnung, gleicher Kontext bei Rückgaben; nicht unterstützter Export verweigert. Handle-Semantik aus § 6 wird ausdrücklich geprüft. |
| T12 | Read, Write, Append, Touch, Mkdir, Chmod/Chown, Copy, Move, Rename, Delete; Quelle und Ziel separat außerhalb platzieren. | Beide Seiten werden geprüft. Zielstrings erben; explizite Zielobjekte behalten eigene Autorisierung. Verweigerung vor unautorisierten Änderungen. |
| T13 | Fehlende Blattdatei, nicht lesbarer Parent, falscher Parent-Typ, Stat-/Resolver-Fehler, unvollständiger Kontext. | Nur bewiesenes Fehlen ist normal; alles andere wirft. `assertFileTarget()` erzeugt nichts; `exists()` verschluckt keine Fehler. |
| T14 | Swap von Datei, Parent und Root zwischen Prüf- und Zugriffsschritten; gezielt auch vor Rename/Delete/Truncate. | Im Atomic-Modus vollständige sichere Operation oder Vorab-Ablehnung; nie eine nachträgliche Exception erst nach unautorisiertem Zugriff. |
| T15 | Fehlendes Backend, nicht unterstützte Flags, wiederholtes Auflösungsversagen; Magic Links und Bind-Mount unter Root. | Exception ohne schwächeren Fallback; begrenzte Retries behalten alle Restrictions. Unsichere Schreiboperationen bleiben gesperrt. |
| T16 | Reguläre Datei mit mehreren Hardlinks; Verzeichnis mit normalem Link-Zähler; Link-Austausch vor Dateiöffnung. | Optionale Hardlink-Ablehnung am autorisierten Objekt ohne vorheriges Truncate; kein Verzeichnis-Fehlalarm. Keine Behauptung dauerhafter Link-Isolation. |
| T17 | Temp-Anlage, fehlgeschlagenes Rename und Cleanup bei ausgetauschtem Ziel; definierte `umask`. | Keine Datei außerhalb der Root, keine ungewollte Rechteausweitung, kein unsicheres Cleanup oder Copy/Delete-Fallback. |
| T18 | Header fehlt, Header kaputt, unverändert gerendertes Dokument; unsichere YAML-Objektdecodierung und Policy-Fehler. | Optional `null` nur ohne Header; Parsing-Exception bei kaputtem Header; bytegleicher Snapshot; unsichere Verarbeitung vor Parser-Seiteneffekten ablehnen. |
| T19 | Schiller-Plan enthält `a` und `a/b`; späterer erlaubter I/O-Fehler nach erfolgreicher Vorprüfung. | Kollision vor erstem Schreiben; keine Transaktionsgarantie für spätere I/O-Fehler. Nach einem Fehler kein Weiterarbeiten mit Defaults. |
| T20 | PHPDocs sämtlicher öffentlicher Options-Einstiege, Ableitungen und Exporte gegen Key-Schema/API-Inventar abgleichen. | Shape, Typen, Defaults, Risiken, Vererbung und Exceptions vollständig; kein neuer undokumentierter Key oder ungeprüfter Erzeugungs-/I/O-Weg. |

### § 12.2 Was ein Sicherheitstest tatsächlich beweisen muss

**Nicht nur die Exception prüfen.** Das unabhängige Test-Orakel muss nachweisen, dass keine unautorisierten Nutzdaten zurückgegeben und außerhalb liegende, nie autorisierte Sentinel-Dateien weder verändert noch ersetzt, gelöscht oder in ihren Rechten verändert wurden. Ein instrumentiertes Backend zeichnet auf, ob vor der Verweigerung bereits ein verbotener Nutzdatenzugriff, Truncate oder eine Mutation versucht wurde. Integrationstests prüfen zusätzlich die tatsächlichen Dateien; eine identische Options-Property allein genügt nicht. [neu]

Unit-/Property-Tests erzeugen Kombinationen von Roots, Bool-Werten und Ableitungsketten und prüfen die Monotonie-Invariante mit einem unabhängig formulierten Erwartungsmodell. Positive Fälle gehören dazu: interne Dateien bleiben lesbar, erlaubte interne Links funktionieren auf einem dafür freigegebenen Backend, und ein bewusst gesetztes `followSymlinks=true` bleibt bei einem bloßen Cast erhalten. Nur „alles wirft“ ist kein korrekter Nachweis. [neu]

**Gezielte Mutationstests sind ein Freigabekriterium:** Je einen Vererbungsweg absichtlich ohne Kontext erzeugen, `rootDir` nach `null` mutieren, No-Follow verlieren, Hardlinks wieder erlauben, Atomic abschalten, einen Options-Patch mit Defaults auffüllen oder einen Policy-Fehler als `false` verschlucken. Jede Mutation muss mindestens einen passenden Test rot machen. Bleibt ein solcher Fehler unentdeckt, ist die Schutzfunktion für diesen Weg nicht ausreichend geprüft; reine Zeilen-Coverage ersetzt das nicht. [neu]

Race-Tests verwenden deterministische Synchronisationspunkte zwischen Auflösung, Öffnen und Mutation, nicht nur zufällige Sleeps; zusätzliche Prozess-Stresstests sind ergänzend. Ein Mock allein belegt keine Kernel-Garantie, deshalb ist pro unterstütztem Backend eine reale Conformance-Suite nötig. Berechtigungstests laufen ohne Root-Sonderrechte. Kann CI Symlinks, Hardlinks, Mounts oder ein Atomic-Backend nicht bereitstellen, wird der Teil als nicht geprüft ausgewiesen und darf nicht als bestandener Sicherheitsnachweis gelten. Produktionscode verwendet keine abschaltbaren PHP-`assert()`-Anweisungen als Zugriffsschutz. [neu]

### § 12.3 Konkrete Review-Punkte im heutigen Code

Bestandsabgleich dieses Reviews: Commit `77d9e720bd2ea9fefe66b676457220a0e2d5d3a3`. Die heutige Library implementiert den neuen Kontext noch nicht. In [PhoreUri](https://github.com/phore/phore-filesystem/blob/77d9e720bd2ea9fefe66b676457220a0e2d5d3a3/src/PhoreUri.php) nimmt der constructor einen String entgegen; unter anderem `getDirname()`, `clean()`, `asFile()` und Assertions erzeugen weitere Objekte aus Pfaden. Diese Erzeugungsstellen müssen bei der Implementierung vollständig auf den Vererbungsweg umgestellt werden. Eine bloße Ergänzung des Options-Parameters an den drei Factories wäre unzureichend. [neu]

In [FileStream](https://github.com/phore/phore-filesystem/blob/77d9e720bd2ea9fefe66b676457220a0e2d5d3a3/src/FileStream.php) überschreibt `fopen()` derzeit die Property mit dem Dateiobjekt durch einen String, und `fclose()` erzeugt daraus ein neues `PhoreFile`. Genau diese Übergänge benötigen T11; das Phore-Objekt darf beim Öffnen nicht durch seinen Anzeigepfad ersetzt werden. `PhoreUri::assertDirectory(true)` enthält außerdem ein unbedingtes `chmod(..., 0777)`, das im Policy-Ausbau nicht unverändert als sichere Anlage gelten darf. [neu]

Die gelesenen [UriTest](../tests/UriTest.php)-Fälle prüfen grundlegende Typ-/Pfadfälle, nicht den neuen Restrictions-Vertrag. [ExamplesTest](../tests/ExamplesTest.php) prüft den Exit-Code der PHP-Beispiele; das ist kein Nachweis der negativen Policy-Fälle oder von Race-Sicherheit. Dieses Review konkretisiert deshalb den Testauftrag, führt aber keine noch nicht implementierte Sicherheits-API aus. Consumer-Schutzprüfungen bleiben bis zur tatsächlichen Implementierung und passenden Freigabe erhalten. [neu]

### § 12.4 Quellen und Implementierungsabgleich

Bestandsabgleich: [PhoreUri](../src/PhoreUri.php), [PhoreDirectory](../src/PhoreDirectory.php), [PhoreFile](../src/PhoreFile.php), [Factories](../src/functions.php), [FileStream](../src/FileStream.php) und [SchillerAutomation](https://github.com/leuffen/leuffen-shiller-lib/blob/main/src/Automation/SchillerAutomation.php). Plattformgrundlagen: [PHP realpath](https://www.php.net/manual/en/function.realpath.php), [PHP clearstatcache](https://www.php.net/manual/en/function.clearstatcache.php), [PHP yaml_parse](https://www.php.net/manual/en/function.yaml-parse.php), [Linux openat2](https://man7.org/linux/man-pages/man2/openat2.2.html), [Linux open](https://man7.org/linux/man-pages/man2/open.2.html), [Linux rename](https://man7.org/linux/man-pages/man2/rename.2.html) und [Linux link](https://man7.org/linux/man-pages/man2/link.2.html). [geändert]
