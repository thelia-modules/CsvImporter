# CsvImporter

Ce module permet d'importer et de mettre à jour votre catalogue de produit via un ou plusieurs fichiers CSV,
et un répertoire d'images.

L'importation est réalisé en ligne de commande :

`php Thelia csvimporter:import-catalog <directory>`

`<directory>` est le répertoire où se trouvent les fichiers CSV et le répertoire `Images`

Ce répertoire doit être nommé Images, et se trouver dans le même répertoire que vos fichiers CSV

Les colonnes du fichier sont les suivantes (provisoire) :

- Titre du produit (Description)
- Famille
- Sous-Famille
- Marque
- Niveau 1
- Niveau 2
- Niveau 3
- Niveau 4
- Description courte
- Description Longue
- Règle de taxe
- Prix du Produit HT
- Prix TTC
- Poids
- IMG
- Référence Produit (Code)
- D:Couleur
- C:Type de Batterie
- C: Contenance
- C: Ohm
- EAN

Une colonne commençant par `D:` est une valeur de déclinaison
Une colonne commençant par `C:` est une valeur de caractéristique

Les colonnes ne doivent pas suivre un ordre particulier. Le fichier CsvImporter/Config/csv_mapping.yaml permet de définir
le mapping des colonnes avec leur rôle dans Thelia, hors déclinaisons et caractéristiques, qui sont dynamiques. Exemple :

```yaml
mappings:
  product_reference: "Référence Produit (Code)"
  product_title: "Titre du produit (Description)"
  family: "Famille"
  sub_family: "Sous-Famille"
  brand: "Marque"
  level_1: "Niveau 1"
  level_2: "Niveau 2"
  level_3: "Niveau 3"
  level_4: "Niveau 4"
  tax_rule: "Règle de taxe"
  price_excl_tax: "Prix du Produit HT"
  price_incl_tax: "Prix TTC"
  weight: "Poids"
  ean: "EAN"
  short_description: "Description courte"
  long_description: "Description Longue"
  image: "IMG"
```
