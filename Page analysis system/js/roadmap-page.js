/* ═══ roadmap-page.js — CSP-safe JS for roadmap-30d.html ═══
 * Extracted from inline <script> for CSP compliance. v1.0
 */
document.addEventListener("DOMContentLoaded", function() {
  // Intersection Observer for animation
  var observer = new IntersectionObserver(function(entries) {
    entries.forEach(function(entry, i) {
      if (entry.isIntersecting) {
        setTimeout(function() {
          entry.target.classList.add('visible');
        }, i * 200);
      }
    });
  }, { threshold: 0.1 });

  document.querySelectorAll('.week-block').forEach(function(block) {
    observer.observe(block);
  });
});
