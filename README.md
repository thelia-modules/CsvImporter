# CsvImporter

Import et simulation d'import d'un catalogue produit depuis un fichier CSV, en ligne de commande.

```
php Thelia csvimporter:import-catalog <fichier.csv> --save-mapping="Fournisseur X" --dry-run
php Thelia csvimporter:import-catalog <fichier.csv> --mapping="Fournisseur X"
```

## Depuis la 1.x

La 2.0.0 réécrit le module. Ces classes publiques de la 1.x n'existent plus : `Command\ImportProducts`, `Controller\ConfigurationController`, `Hook\HookManager`, `Service\CsvParserService` et `Service\CsvProductImporterService`. La commande garde son nom, `csvimporter:import-catalog` ; un code qui appelait ces classes directement est à adapter.

## Options

- `--save-mapping=<nom>` : devine l'association colonne → champ d'après l'en-tête du fichier, l'affiche et la garde sous un nouveau profil.
- `--mapping=<id ou nom>` : réutilise un profil existant. L'un des deux est obligatoire.
- `--mode=create_or_update` (défaut), `update_only` ou `create_only`.
- `--dry-run` : simule. Même code que l'import réel, sans aucune écriture dans le catalogue.
- `--images-dir=<dossier>` : dossier des fichiers nommés dans la colonne image (une URL http(s) ou un nom de fichier ; plusieurs séparés par `|`).
- `--images-only` : charge seulement les images des produits déjà présents.

## Code de sortie

- `0` : terminé, toutes les lignes traitées.
- `1` : l'import n'a pas tourné (fichier illisible, profil inadapté, autre import en cours).
- `2` : arguments refusés.
- `3` : terminé, mais des lignes ont été refusées (leur motif est affiché et stocké dans le rapport du job).

## Fichier

- Le séparateur et l'encodage sont détectés ; un profil peut les imposer.
- La colonne de référence est obligatoire : elle identifie le produit à créer ou à mettre à jour.
- Une colonne `C:<nom>` est une caractéristique. Les colonnes `D:<nom>` (déclinaisons) ne sont pas encore importées.
- Une ligne fausse n'arrête pas l'import : elle est refusée avec son motif, les autres s'écrivent, chacune dans sa propre transaction.
- Une cellule vide n'efface jamais le champ d'un produit existant.

## Images

Chargées après le produit, dans un second passage : l'échec d'un téléchargement ne bloque pas la création. Les adresses internes ou privées, les redirections et les schémas autres que http(s) sont refusés ; 10 Mo et 40 millions de pixels au plus ; types JPEG, PNG, GIF et WebP vérifiés sur le contenu. Une image déjà présente (même contenu) n'est pas ajoutée deux fois. La simulation n'appelle jamais le réseau.

## Export

`php Thelia csvimporter:export-catalog <fichier.csv>` écrit les produits simples dans le format que l'import relit (les produits à déclinaisons sont comptés et laissés de côté, les images ne sont pas exportées).

- Une caractéristique à plusieurs valeurs s'écrit `Rouge|Bleu`, et l'import relit ce format.
- Une cellule qui commencerait par `=`, `+`, `-` ou `@` (hors nombre) est préfixée d'une apostrophe à l'export pour ne pas s'exécuter dans un tableur ; l'import retire cette apostrophe.
- Un nombre à trois décimales exactes s'écrit avec un zéro de plus (`1,2340`) : `1,234` serait ambigu avec un millier.
- Les descriptions sont nettoyées à l'écriture (balises et attributs non sûrs retirés) ; un texte déjà identique après nettoyage n'est pas réécrit.

Un fichier d'exemple est livré dans `Resources/example/catalog-example.csv` (10 lignes, dont une au prix illisible pour montrer le rapport).

Un module déposé après l'installation de la boutique n'a pas ses tables après `module:activate` : lancer `php bin/console module:schema:apply CsvImporter`, puis vider le cache.

## Écrans du back-office

Outils > Import de catalogue (thème `default-twig`). Dépôt du fichier, correspondance des colonnes, simulation, import réel, rapport (filtrable, téléchargeable en CSV).

- Droit propre au module (profil > modules) : lecture = voir jobs et rapports ; création = déposer, enregistrer la correspondance, simuler ; modification = importer pour de vrai. Attention : le droit de modification du module donne l'écriture dans le catalogue (produits, caractéristiques, images), quels que soient les droits du profil sur ces ressources.
- Écritures en POST, jeton dans le corps ; journal `admin_log` sans cookie ni contenu du fichier.
- Un import réel exige une simulation terminée de moins de 24 h, la case de confirmation, une correspondance inchangée depuis la simulation, et n'est jouable qu'une fois par simulation. Le traitement part en tâche de fond : avec une file (`MESSENGER_TRANSPORT_DSN` renseigné), un message `RunCatalogImportJob` est posé sur la file `async_heavy` du cœur et un worker (`bin/console messenger:consume async_heavy`) le prend, comme les exports et imports du cœur ; sans file, un processus console (`csvimporter:run-job <id>`) est lancé à la place. Si le job n'a pas démarré, la page donne la commande à lancer. Une double livraison du message ne rejoue rien. Le module exige un cœur 3.3.0 ou plus. Un job immobile peut être abandonné.
- Les fichiers déposés restent dans `var/csv-importer/uploads` (hors docroot, 0600), sans purge automatique. Plafonds : 50 Mo, 200 colonnes.
- Archive d'images facultative (`.zip`, 200 Mo au plus) déposée avec le fichier : les noms de la colonne image s'y lisent (`photos/mug.png`), sous-dossiers compris. Dépaquetée au dépôt à côté du fichier (`<jeton>.images/`, 0600), puis retrouvée par le job de fond ; la simulation signale tout nom absent de l'archive. Elle est refusée en entier si un nom sort de son dossier (`..`, chemin absolu, antislash, `:`), si elle contient un lien symbolique, un doublon (casse comprise), plus de 5 000 fichiers, une image de plus de 10 Mo ou plus de 500 Mo une fois décompressée. Les fichiers qui ne sont pas des images (JPEG, PNG, GIF, WebP) et les dossiers `__MACOSX` sont ignorés. Un dossier racine unique n'est pas retiré : le nom du CSV doit l'inclure.
- Limite connue : un changement de correspondance dans la même seconde que la demande d'import n'est pas détecté.
