/* preflow/fields: starts an embed field's video on a click (PrivacyEmbedPlayer's markup, or a site's
   own with the same hooks). Until then the iframe waits in an inert template element, so nothing loads from
   the video's host. One listener on the document serves every player, also those a later swap adds. */
function preflowEmbedPlayers(doc) {
    if (doc.preflowEmbedPlayers) {
        return;
    }
    doc.preflowEmbedPlayers = true;
    doc.addEventListener('click', function (event) {
        var button = event.target instanceof Element ? event.target.closest('[data-preflow-embed-play]') : null;
        var box = button ? button.closest('[data-preflow-embed]') : null;
        var frame = box ? box.querySelector('template[data-preflow-embed-frame]') : null;
        if (!frame) {
            return;
        }
        event.preventDefault();
        var content = frame.content.cloneNode(true);
        var iframe = content.querySelector('iframe');
        button.replaceWith(content);
        frame.remove();
        box.classList.add('is-playing');
        if (iframe) {
            iframe.focus();
        }
    });
}
preflowEmbedPlayers(document);
