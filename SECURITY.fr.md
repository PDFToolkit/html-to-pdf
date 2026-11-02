# Politique de sécurité

[English](SECURITY.md) · [Français](SECURITY.fr.md)

Cette politique décrit les limites de sécurité de `pdftoolkit/html-to-pdf`.
Elle complète le [guide d’utilisation et de ressources](docs/fr/usage.md) et le
[profil d’entrée pris en charge](docs/fr/coverage.md). La bibliothèque est un
composant de conversion PHP, pas un bac à sable ni un service de certification
de sécurité documentaire.

## Ressources explicites et actions externes

L’application appelante fournit les chaînes HTML/CSS et les octets des polices
et des images. Le pipeline de conversion ne télécharge aucune ressource, ne
résout aucun chemin du système de fichiers, ne recherche aucune police système,
ne lance aucun programme externe et n’exécute aucun JavaScript.
Les clés de `ResourceCatalog` sont des identifiants exacts, sensibles à la casse.
Une clé ressemblant à une URL, un chemin relatif ou une chaîne `data:` ne
déclenche aucun téléchargement ni lecture de fichier : images et feuilles de
style liées doivent déjà être présentes dans le catalogue.

Les lectures de fichiers, téléchargements et écritures de PDF du code
d’intégration restent sous la responsabilité de l’application. Appliquer ses
autorisations et limites d’entrée avant de charger les octets ou de construire
les objets ressources. En particulier, ne pas transformer automatiquement les
noms de ressources arbitraires d’un document en lectures de fichiers ou requêtes.

Les liens textuels absolus ASCII HTTP, HTTPS et mailto admis deviennent des
annotations URI dans le PDF. La conversion ne les suit pas, mais un lecteur PDF
peut ouvrir leur destination lorsqu’une personne les active. Appliquer avant
conversion les restrictions de destination propres à l’application. Actions
JavaScript, scripts incorporés, soumission de formulaires et destinations
internes sont hors profil ; cette API ne sert pas à assainir du HTML.

## Limites de ressources et du processus

`ConversionLimits` borne l’entrée, le travail CSS, les nœuds, la profondeur, le
texte, les pages, les commandes de dessin, les ressources et la sortie. Le
[guide d’utilisation](docs/fr/usage.md#limites) donne toutes les valeurs par
défaut. Chaque conversion possède ses compteurs de travail. Le budget du
catalogue comprend aussi les images et feuilles de style inutilisées. Le moteur
de rendu vérifie également les octets cumulés des polices/images et les pixels
des images employés dans la sortie.

`ImageAsset` vérifie les octets encodés et les dimensions avant incorporation.
`FontLimits` borne séparément les octets de chaque police, les enregistrements
de mise en page, les opérations de shaping, leur profondeur et les glyphes
produits. Les objets polices analysent leurs données à la construction, puis
préparent les tables de mise en page au besoin ; les objets réutilisés peuvent
conserver ces tables préparées. Analyse et préparation peuvent consommer bien
plus de mémoire que les octets d’origine. Les exemples Lato mesurés demandent
environ 52 Mio par variante préparée : cette mesure ne constitue pas un plafond
pour les autres polices.

La limite d’octets PDF est vérifiée **après sérialisation**. Ni elle ni les autres
budgets de la bibliothèque n’imposent un plafond mémoire strict au processus ou
une échéance de temps réel. Pour des entrées non fiables, configurer les limites
mémoire/temps des workers applicatifs et borner leur concurrence ainsi que le
nombre d’objets ressources conservés. Augmenter une limite n’ajoute aucune
fonction absente et ne garantit pas que le processus disposera des ressources
nécessaires. Maintenir PHP et les dépendances Composer à jour.

## Erreurs et diagnostics

`UnsupportedPolicy::Reject` est la valeur par défaut : les fonctions reconnues
absentes arrêtent la conversion. `UnsupportedPolicy::Report` autorise
volontairement des approximations documentées et produit des diagnostics que
l’application doit examiner. Par exemple, une cible de lien HTML non admise est
retirée avec un diagnostic en mode Report ; cette politique n’autorise jamais
une annotation URI dangereuse. Ressources absentes, glyphes ou variantes de
police absents, shaping non pris en charge, débordements indivisibles et budgets
épuisés peuvent toujours arrêter la conversion dans les deux modes. Une
exception ne produit aucun `ConversionResult` partiel présenté comme réussi.

Les diagnostics et messages d’exception peuvent contenir des valeurs issues du
document. Les échapper pour l’interface de destination et éviter de journaliser
du contenu confidentiel. Une conversion réussie, une structure balisée ou des
validations d’exemples réussies ne démontrent ni une conformité HTML/CSS complète,
ni une certification PDF/UA ou PDF/A, ni l’absence de vulnérabilités.

## Signaler une vulnérabilité

Contacter le propriétaire du dépôt en privé à
[contact@roukmoute.fr](mailto:contact@roukmoute.fr), l’adresse inscrite dans
`composer.json`. Fournir la version ou le commit du paquet, la version de PHP,
les limites pertinentes, le comportement attendu et une reproduction minimale
sans données personnelles ni identifiants. Préciser tout épuisement de
ressources ou toute action externe observés. Éviter de publier des charges
sensibles dans un ticket public avant coordination avec le mainteneur. Cette
politique ne promet aucun délai de réponse ou de correction.
