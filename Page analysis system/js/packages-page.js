/* ═══ packages-page.js — CSP-safe JS for packages.html ═══
 * Extracted from inline <script> for CSP compliance. v1.0
 */
document.addEventListener("DOMContentLoaded", function() {
  var id = new URLSearchParams(window.location.search).get('id');
  if (id) {
    document.querySelectorAll('a[href^="checkout.html"]').forEach(function(link) {
      var href = link.getAttribute('href');
      link.setAttribute('href', href + (href.indexOf('?') !== -1 ? '&id=' : '?id=') + id);
    });
  }
});
