=== Genius INFast ===
Contributors: ingeniusagency
Tags: woocommerce, facturation, infast, automation, comptabilite
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Synchronisez automatiquement WooCommerce avec INFast et automatisez la création de vos factures clients.

== Description ==

Genius INFast connecte votre boutique WooCommerce à la plateforme INFast afin d'automatiser la facturation et le suivi client. Chaque commande admissible crée (ou met à jour) le client dans INFast, génère la facture correspondante, enregistre le paiement et peut déclencher l'envoi automatique du document au client final. L'interface d'administration vous permet de piloter les options clés et de déclencher la synchronisation des produits en un clic.

== Fonctionnalités principales ==

* Création ou réutilisation des clients INFast à partir des commandes WooCommerce.
* Génération des factures avec enregistrement automatique des paiements et envoi optionnel par e-mail.
* Choix des statuts de commandes WooCommerce qui déclenchent la synchronisation.
* Synchronisation manuelle des fiches produits WooCommerce vers INFast.
* Nettoyage automatique du lien client lorsque INFast signale la suppression d'un client via webhook.
* Interface d'administration dédiée pour renseigner les identifiants API, configurer les préférences et lancer les actions de maintenance.

== Prérequis ==

* WordPress 6.0 ou supérieur.
* WooCommerce installé et activé.
* Un compte INFast avec accès API (client_id et client_secret).
* PHP 7.4 ou supérieur.

== Installation ==

1. Décompressez l'archive du plugin et copiez le dossier `genius-infast` dans `wp-content/plugins/`.
2. Activez « Genius INFast » via le menu **Extensions** de WordPress.
3. Rendez-vous dans **WooCommerce → INFast** (ou **Réglages → INFast** si WooCommerce est absent) pour finaliser la configuration.

== Configuration ==

Sur l'écran d'options du plugin vous pouvez :

* Renseigner votre `ID client` et `Secret client` INFast pour autoriser les appels API.
* Activer ou désactiver l'envoi automatique des factures et ajouter un destinataire en copie (champ « Destinataire en copie »).
* Sélectionner les statuts de commande WooCommerce qui déclenchent la génération de facture (par défaut `Terminée`).
* Choisir de ne pas envoyer les descriptions produits ou d'ajouter une mention légale personnalisée sur les documents.
* Définir un `Jeton webhook` utilisé pour sécuriser la communication entrante depuis INFast.
* Lancer la synchronisation complète des produits ou délier les produits existants via les formulaires dédiés.

Enregistrez toujours vos modifications en cliquant sur **Enregistrer les modifications**.

== Webhook INFast ==

1. Dans les réglages du plugin, définissez un jeton secret dans le champ « Jeton webhook ».
2. Depuis votre compte INFast, créez un webhook `customer.deleted` pointant vers l'URL : `https://votresite.tld/wp-json/genius-infast/v1/customer-deleted`.
3. Ajoutez le jeton défini précédemment dans l'entête `Authorization: Bearer {votre_jeton}` côté INFast.

Lorsqu'un client est supprimé dans INFast, le plugin supprime automatiquement la métadonnée `_genius_infast_customer_id` associée à l'utilisateur WooCommerce correspondant.

== Synchronisation des produits ==

Le module de synchronisation permet d'envoyer vos produits WooCommerce vers INFast et de délier les références existantes si nécessaire. Les actions sont accessibles depuis la page de configuration du plugin et respectent vos préférences (par exemple ignorer les descriptions produits).

== FAQ ==

= Les factures ne se créent pas, que faire ? =

Vérifiez que vos identifiants API sont valides (bouton « Tester la connexion ») et que le statut de la commande figure bien dans la liste des statuts déclencheurs. Consultez également les notes de commande pour identifier d'éventuels messages d'erreur retournés par l'API INFast.

= Puis-je envoyer les factures à une adresse interne en copie ? =

Oui, utilisez le champ « Destinataire en copie » pour indiquer une adresse e-mail qui recevra chaque facture envoyée automatiquement par INFast.

== Support ==

Pour toute question, contactez l'équipe Ingenius Agency via votre interlocuteur habituel ou l'adresse support fournie avec votre contrat INFast.

== Changelog ==

= 1.0.0.1 =
* Mise à jour automatique.


= 1.0.0 =
* Version initiale – synchronisation des commandes, paiements, e-mails et prise en charge du webhook de suppression de clients.
