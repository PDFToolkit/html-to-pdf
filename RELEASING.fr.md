# Publication

[English](RELEASING.md) · [Français](RELEASING.fr.md)

1. Garder des commits centrés sur un comportement complet et ses tests. Mettre
   à jour guides, couverture et historique dans les deux langues. N’utiliser
   que des dépendances stables, sans surcharge path/VCS.
2. Vérifier Composer, plateforme, audit, PHPUnit, PHPStan et style. Générer tous
   les exemples puis exécuter l’oracle indépendant qpdf/pypdf/Poppler. Inspecter
   chaque page rendue ; conserver versions des outils, empreintes PDF et rapports.
   Un contrôle de syntaxe ne constitue pas une certification PDF/UA ou PDF/A.
3. Exiger une CI réussie sur PHP 8.3, 8.4 et 8.5 pour le commit candidat exact.
4. Vérifier l’absence locale et distante du tag. Créer un tag annoté immuable
   sur le commit validé et une release GitHub avec profil, limites et preuves.
5. Vérifier le SHA du tag distant et la version/référence Packagist. Installer
   la version stable exacte dans un consommateur neuf, sans dépendances de
   développement ni dépôt particulier, puis exécuter une conversion documentée.
6. Actualiser la documentation centrale depuis la version et le SHA publiés.
   Publier la documentation séparément, sans nouvelle version des modules inchangés.

Première version : 0.1.0. Avant 1.0, une capacité nouvelle incrémente la mineure ;
une correction compatible incrémente le correctif. Ne jamais déplacer un tag publié.
