<?php

// Les textes des pages et des e-mails des membres. Un site modifie des clés isolées dans son lang/fr/members.php.
// Les paramètres :name sont remplis par t(), les marqueurs {name} des e-mails par le mailer.
return [
    'form' => [
        'name' => 'Nom',
        'email' => 'E-mail',
        'password' => 'Mot de passe',
        'password_hint' => 'Au moins :min caractères.',
        'consent' => "J'accepte que mes données soient utilisées pour ce compte (:link).",
        'privacy' => 'politique de confidentialité',
        'submit' => 'Créer un compte',
        'registered' => 'Déjà inscrit ?',
        'login' => 'Se connecter',
    ],
    'closed' => [
        'title' => 'Les inscriptions sont fermées',
        'text' => 'Les nouvelles inscriptions sont suspendues pour le moment. Si vous avez déjà un compte, vous pouvez vous connecter.',
    ],
    'errors' => [
        'login' => 'E-mail ou mot de passe incorrect.',
        'summary' => 'Veuillez vérifier les champs signalés.',
        'name' => 'Veuillez indiquer votre nom.',
        'email' => 'Veuillez indiquer une adresse e-mail valide.',
        'password' => 'Utilisez au moins :min caractères.',
        'consent' => 'Veuillez donner votre accord pour que votre compte puisse être créé.',
        'throttled' => 'Trop de tentatives, veuillez réessayer plus tard.',
    ],
    'inbox' => [
        'title' => 'Consultez votre boîte de réception',
        'text' => 'Un lien est en route vers :email. Cliquez dessus pour confirmer votre adresse.',
        'hint' => 'Le lien est valable :validity. Rien reçu ? Regardez dans votre dossier de courrier indésirable.',
    ],
    'confirm' => [
        'title' => 'Confirmez votre e-mail',
        'text' => "Un clic, et c'est terminé.",
        'button' => 'Confirmer mon e-mail',
        'used_title' => 'Ce lien ne fonctionne plus',
        'used' => 'Ce lien a déjà été utilisé ou a expiré. Vous pouvez vous connecter ou vous inscrire à nouveau.',
        'login' => 'Se connecter',
        'register' => "S'inscrire à nouveau",
    ],
    'thanks' => [
        'tag' => 'En attente de validation',
        'title' => 'Merci de votre intérêt',
        'text' => "Votre e-mail est confirmé. Votre inscription va être examinée ; vous recevrez un e-mail dès qu'une décision aura été prise.",
    ],
    'login' => [
        'title' => 'Se connecter',
        'submit' => 'Se connecter',
        'forgot' => 'Mot de passe oublié ?',
        'no_account' => 'Pas encore de compte ?',
        'register' => "S'inscrire",
    ],
    'forgot' => [
        'title' => 'Mot de passe oublié ?',
        'text' => 'Indiquez votre e-mail pour recevoir un lien qui vous permettra de choisir un nouveau mot de passe.',
        'submit' => 'Envoyer le lien',
        'sent' => 'Si un compte existe pour cette adresse, un lien est en route.',
        'back' => 'Retour à la connexion',
    ],
    'reset' => [
        'title' => 'Choisissez un nouveau mot de passe',
        'password' => 'Nouveau mot de passe',
        'repeat' => 'Répétez le mot de passe',
        'submit' => 'Enregistrer le mot de passe',
        'mismatch' => 'Les deux mots de passe ne correspondent pas.',
        'expired_title' => 'Ce lien a expiré',
        'expired' => 'Ce lien a déjà été utilisé ou a expiré. Demandez-en un nouveau.',
        'again' => 'Envoyer un nouveau lien',
    ],
    'resend' => [
        'title' => "Veuillez d'abord confirmer votre e-mail",
        'text' => "Votre mot de passe est correct, mais votre adresse n'est pas encore confirmée. Faites-vous renvoyer le lien.",
        'button' => 'Renvoyer le lien',
    ],
    'account' => [
        'title' => 'Votre compte',
        'changed' => 'Mot de passe modifié.',
        'deleted' => 'Votre compte a été supprimé.',
        'current' => 'Mot de passe actuel',
        'password_title' => 'Changer votre mot de passe',
        'password_submit' => 'Enregistrer le nouveau mot de passe',
        'delete_title' => 'Supprimer votre compte',
        'delete_text' => 'Cette action supprime votre compte et vos données personnelles. Elle est irréversible. Vous pouvez vous réinscrire à tout moment.',
        'confirm' => 'Je comprends que cette action supprime mon compte.',
        'delete_submit' => 'Supprimer mon compte',
        'errors' => [
            'current' => 'Le mot de passe actuel est incorrect.',
            'delete_current' => 'Le mot de passe actuel est incorrect.',
            'mismatch' => 'Les deux mots de passe ne correspondent pas.',
            'confirm' => 'Veuillez confirmer que vous comprenez que cette action supprime votre compte.',
        ],
    ],
    // Durée de validité d'un lien ({validity} / :validity), d'après confirm_ttl et reset_ttl.
    'validity' => [
        'hours' => '{1} une heure|[2,*] :count heures',
        'minutes' => '{1} une minute|[2,*] :count minutes',
    ],
    // Objet et texte de chaque e-mail (voir lang/en/members.php).
    'mail' => [
        'confirm' => [
            'subject' => 'Confirmez votre adresse e-mail',
            'body' => "Bonjour {name},\n\nVeuillez confirmer votre adresse e-mail : {link}\n\nLe lien est valable {validity}. Si vous ne vous êtes pas inscrit, ignorez ce message.",
        ],
        'existing' => [
            'subject' => 'Votre compte',
            'body' => "Bonjour {name},\n\nQuelqu'un (vous, espérons-le) a tenté de s'inscrire avec cette adresse, mais vous avez déjà un compte.\n\nSe connecter : {login_url}\nMot de passe oublié ? {reset_url}\nLe lien de réinitialisation est valable {validity}.",
        ],
        'reset' => [
            'subject' => 'Réinitialisez votre mot de passe',
            'body' => "Bonjour {name},\n\nChoisissez un nouveau mot de passe ici : {link}\n\nLe lien est valable {validity}. Si vous n'avez rien demandé, ignorez ce message.",
        ],
        'notice' => [
            'subject' => '{name} attend une validation',
            'body' => "{name} <{email}> a confirmé son adresse e-mail et attend une validation.{details}\n\nOuvrir dans Folio : {folio_url}",
        ],
        'accepted' => [
            'subject' => 'Votre compte est prêt',
            'body' => "Bonjour {name},\n\nVotre compte est prêt. Connectez-vous ici : {login_url}\n\nCordialement",
        ],
        'declined' => [
            'subject' => 'À propos de votre inscription',
            'body' => "Bonjour {name},\n\nMerci de votre intérêt. Malheureusement, nous ne pouvons pas vous ouvrir de compte pour le moment.\n\nCordialement",
        ],
    ],
];
