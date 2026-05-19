/* ═══ packages-page.js — CSP-safe JS for packages.html ═══
 * Extracted from inline <script> for CSP compliance. v1.0
 */
document.addEventListener("DOMContentLoaded", function() {
  var urlParams = new URLSearchParams(window.location.search);
  var id = urlParams.get('id');
  var token = urlParams.get('token') || sessionStorage.getItem('last_assessment_token') || '';
  if (id) {
    document.querySelectorAll('a[href^="checkout.html"]').forEach(function(link) {
      var href = link.getAttribute('href');
      var urlParts = href.split('?');
      var base = urlParts[0];
      var params = new URLSearchParams(urlParts[1] || '');
      params.set('id', id);
      if (token) {
        params.set('token', token);
      }
      link.setAttribute('href', base + '?' + params.toString());
    });
  }
});
