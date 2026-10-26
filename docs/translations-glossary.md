# Translations glossary

The fixed terms of the admin interface (Folio and the packages that add to it: rbac, members,
codes, validation) in English, German and French. A new text uses these words. A site that
adds `lang/de/*.php` or `lang/fr/*.php` files, or changes texts on the Translations screen,
should use them too, so its screens read like the delivered ones.

## Register and spelling

- **German:** "du" in the admin interface and in staff mails ("Wähle dein Passwort"). Standard
  spelling with ß ("Schließen", "Größe", "Freundliche Grüße"). A dash in a sentence is a
  spaced en dash (" – "), as German writes it ("Nicht gespeichert – prüfe deine Verbindung");
  English and French keep the spaced em dash (" — ").
  - Exception: members' **public** texts (`members` group: member pages and mails to
    members) keep "Sie" and their present wording, so public pages stay byte-identical.
- **French:** neutral wording where possible (infinitives on buttons: "Enregistrer",
  "Se connecter"); "vous" where a sentence needs a pronoun ("Vérifiez votre connexion").
  Typographic apostrophe ’. A space before `:`, `;`, `?` and `!`.
- **Numbers and dates** come from `folio_date()` and `folio_number()`: German
  `26.10.2026`, French `26/10/2026`, English `Oct 26, 2026`. A text never writes a date
  format itself.

## Content

| English | German | French |
|---|---|---|
| record / records | Eintrag / Einträge | entrée / entrées |
| page / pages | Seite / Seiten | page / pages |
| content (section) | Inhalte | Contenu |
| library (section) | Bibliothek | Bibliothèque |
| type (content type) | Typ | type |
| field | Feld | champ |
| block / blocks | Block / Blöcke | bloc / blocs |
| link (relation) | Verknüpfung | lien |
| row (matrix) | Zeile | ligne |
| singleton | Singleton | singleton |
| untitled | ohne Titel | sans titre |

## Publishing

| English | German | French |
|---|---|---|
| publish / published | veröffentlichen / veröffentlicht | publier / publié |
| unpublish | Veröffentlichung zurückziehen | retirer la publication |
| draft | Entwurf | brouillon |
| changed (state) | Geändert | Modifié |
| working copy | Arbeitsversion | copie de travail |
| revision | Version | version |
| discard changes | Änderungen verwerfen | abandonner les modifications |
| schedule / publish at | planen / Veröffentlichen am | planifier / Publier le |
| preview / live preview | Vorschau / Live-Vorschau | aperçu / aperçu en direct |
| preview link | Vorschaulink | lien d’aperçu |
| revoke (links) | widerrufen | révoquer |

## Assets

| English | German | French |
|---|---|---|
| asset / assets | Medium / Medien | média / médias |
| file | Datei | fichier |
| upload | hochladen | importer |
| replace | ersetzen | remplacer |
| version (of an asset) | Version | version |
| alt text | Alternativtext | texte alternatif |
| focal point | Fokuspunkt | point focal |
| crop / cut (of a format) | Zuschnitt | recadrage |
| rotate / mirror | drehen / spiegeln | pivoter / miroir |
| poster image | Vorschaubild | image d’affiche |
| usage | Verwendung | utilisation |
| kind (image, video, document) | Art | type |

## People and access

| English | German | French |
|---|---|---|
| staff / staff member | Team / Teammitglied | équipe / membre de l’équipe |
| account | Konto | compte |
| role / roles | Rolle / Rollen | rôle / rôles |
| permission / permissions | Berechtigung / Berechtigungen | autorisation / autorisations |
| population (of a role) | Personenkreis | public |
| grant (a permission) | vergeben / erteilen | accorder |
| administrator | Administrator | administrateur |
| member / members | Mitglied / Mitglieder | membre / membres |
| waiting for approval | Wartet auf Freigabe | En attente de validation |
| accept / decline | annehmen / ablehnen | accepter / refuser |
| invitation | Einladung | invitation |
| sign in / log out | anmelden / abmelden | se connecter / se déconnecter |
| password | Passwort | mot de passe |
| email (field) | E-Mail | adresse e-mail |
| no access | Kein Zugriff | Accès refusé |

## Verbs and buttons

| English | German | French |
|---|---|---|
| save | Speichern | Enregistrer |
| cancel | Abbrechen | Annuler |
| delete | Löschen | Supprimer |
| remove (from a list) | Entfernen | Retirer |
| edit | Bearbeiten | Modifier |
| create / new | Erstellen / Neu | Créer / Nouveau |
| view (permission) | Ansehen | Voir |
| search / filter | Suchen / Filter | Rechercher / Filtrer |
| clear / reset | Zurücksetzen | Effacer / Réinitialiser |
| restore | Wiederherstellen | Restaurer |
| export | Exportieren | Exporter |
| enable / disable (extension) | Einschalten / Ausschalten | Activer / Désactiver |
| enable / disable (account) | Aktivieren / Deaktivieren | Activer / Désactiver |

## The admin itself

| English | German | French |
|---|---|---|
| dashboard | Übersicht | Tableau de bord |
| settings | Einstellungen | Paramètres |
| extension / extensions | Erweiterung / Erweiterungen | extension / extensions |
| cache | Cache | cache |
| translations | Übersetzungen | Traductions |
| delivered text | mitgelieferter Text | texte livré |
| changed text (override) | geänderter Text | texte modifié |
| language | Sprache | langue |
| site | Website | site |

## Codes

| English | German | French |
|---|---|---|
| code / codes | Code / Codes | code / codes |
| purpose | Zweck | usage |
| redeem / redemptions | einlösen / Einlösungen | échanger / échanges |
| uses / max uses | Eingelöst / Höchstens einlösbar | Utilisations / Utilisations max. |
| expires / expired | läuft ab / abgelaufen | expire / expiré |
