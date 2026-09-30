# Doli2Shop — Community Edition

**Module version / Version du module : 2.6.0**
*(filled in automatically at each sync from `lib/version.lib.php` — never edited by hand /
renseignée automatiquement à chaque synchronisation depuis `lib/version.lib.php`, jamais à la main)*

[![License: GPL v3](https://img.shields.io/badge/license-GPL%20v3-029e9c)](LICENSE)

---

## English

This repository is the **Community Edition** of Doli2Shop: the Dolibarr module code
(product/order/stock synchronization with Shopify), licensed under the **GPL v3**. It is a
read-only mirror, synchronized automatically from the maintainer's private repository — pull
requests are not merged here directly (see **Contributing** below).

This edition contains **only the module code**. It has **no connection to the official Shopify
app**, **no license mechanism**, and **no support channel**. You install it, you read it, you
modify it under the terms of the GPL — that is the whole offer.

For a connection to the official Shopify App Store app, update notifications, and e-mail support,
see the **Support Edition** below.

### Community vs. Support

| | **Community** (this repository) | **Support** (paid, ptitetete.org / DoliStore) |
|---|:---:|:---:|
| Module source code (GPL v3) | ✅ | ✅ |
| Install, read, modify freely | ✅ | ✅ |
| Connection to the official Shopify App Store app | ❌ | ✅ |
| License / activation | ❌ (none) | ✅ |
| Software updates | Manual (`git pull` / re-download) | ✅ Included |
| E-mail support | ❌ | ✅ |
| Documentation site | Limited to this README | ✅ Full documentation |

No price is listed here — pricing for the Support Edition lives on the official site, not in this
file, so it never drifts out of date:

- 🌐 Site: <https://doli2shop.ptitetete.org>
- 🛒 DoliStore listing: <https://www.dolistore.com>

### Compatibility

- **Dolibarr**: 18.0 → 24.x
- **PHP**: 7.4 → 8.4

*(source: `need_dolibarr_version` / `max_dolibarr_version` in
`core/modules/modDoli2Shop.class.php`, and the PHP matrix documented for this module family.)*

### Installation

1. Download or clone this repository.
2. Copy its content into `htdocs/custom/doli2shop/` of your Dolibarr install.
3. Enable the module from **Home → Setup → Modules**.
4. Run `composer install --no-dev` inside the module directory if `vendor/` is not already
   present (this Community mirror ships the `vendor/` directory as tracked in the private
   repository — no build step should be required for a normal install).

### Contributing

Issues and pull requests are welcome — see **CONTRIBUTING.md** for coding conventions, commit
format, and the review workflow. Because this repository is a synchronized mirror, an accepted
contribution is cherry-picked back into the private repository by the maintainer rather than
merged directly here; your commit will reappear on this mirror at the next sync.

### License

[GNU General Public License v3.0](LICENSE) — © 2024-2026 Association P'tite Tête.

---

## Français

Ce dépôt est la version **Community** de Doli2Shop : le code du module Dolibarr (synchronisation
produits/commandes/stocks avec Shopify), sous licence **GPL v3**. C'est un miroir en lecture,
synchronisé automatiquement depuis le dépôt privé du mainteneur — les pull requests n'y sont pas
fusionnées directement (voir **Contribuer** ci-dessous).

Cette édition contient **uniquement le code du module**. Elle n'a **aucune connexion à
l'application Shopify officielle**, **aucun mécanisme de licence**, et **aucun canal de support**.
Vous l'installez, vous la lisez, vous la modifiez selon les termes de la GPL — c'est toute l'offre.

Pour la connexion à l'application officielle du Shopify App Store, les notifications de mise à
jour et le support par e-mail, voir la **version Support** ci-dessous.

### Community vs. Support

| | **Community** (ce dépôt) | **Support** (payante, ptitetete.org / DoliStore) |
|---|:---:|:---:|
| Code source du module (GPL v3) | ✅ | ✅ |
| Installer, lire, modifier librement | ✅ | ✅ |
| Connexion à l'application officielle Shopify App Store | ❌ | ✅ |
| Licence / activation | ❌ (aucune) | ✅ |
| Mises à jour du logiciel | Manuelles (`git pull` / retéléchargement) | ✅ Incluses |
| Support par e-mail | ❌ | ✅ |
| Site de documentation | Limité à ce README | ✅ Documentation complète |

Aucun tarif n'est indiqué ici — les tarifs de la version Support vivent sur le site officiel, pas
dans ce fichier, pour ne jamais devenir obsolètes :

- 🌐 Site : <https://doli2shop.ptitetete.org>
- 🛒 Fiche DoliStore : <https://www.dolistore.com>

### Compatibilité

- **Dolibarr** : 18.0 → 24.x
- **PHP** : 7.4 → 8.4

*(source : `need_dolibarr_version` / `max_dolibarr_version` dans
`core/modules/modDoli2Shop.class.php`, et la matrice PHP documentée pour cette famille de
modules.)*

### Installation

1. Télécharger ou cloner ce dépôt.
2. Copier son contenu dans `htdocs/custom/doli2shop/` de votre installation Dolibarr.
3. Activer le module depuis **Accueil → Configuration → Modules**.
4. Lancer `composer install --no-dev` dans le répertoire du module si `vendor/` n'est pas déjà
   présent (ce miroir Community embarque le répertoire `vendor/` tel que suivi dans le dépôt
   privé — aucune étape de build ne devrait être nécessaire pour une installation normale).

### Contribuer

Les issues et pull requests sont bienvenues — voir **CONTRIBUTING.md** pour les conventions de
code, le format de commit, et le workflow de revue. Ce dépôt étant un miroir synchronisé, une
contribution acceptée est reportée (cherry-pick) dans le dépôt privé par le mainteneur plutôt que
fusionnée ici directement ; votre commit réapparaîtra sur ce miroir à la prochaine synchronisation.

### Licence

[GNU General Public License v3.0](LICENSE) — © 2024-2026 Association P'tite Tête.

---

<sub>Association P'tite Tête (loi 1901) · RNA W212013679 · SIRET 900 007 667 00018 · 5 Grande Rue,
21220 URCY, France</sub>
