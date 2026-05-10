/* ═══ animations.js — Shared animations for all sub-pages ═══
 * Extracted from inline <script> blocks in strengths.html,
 * weaknesses.html, recommendations.html, packages.html,
 * journey.html, content.html, plan.html, roadmap-30d.html
 * and other sub-pages.
 *
 * CSP-safe: No eval, no template literals in setTimeout/setInterval,
 * uses string concatenation, textContent instead of innerHTML where
 * possible.
 * v1.0
 */

document.addEventListener("DOMContentLoaded", function() {
  // ── Number Counter Animation ──
  var animateValue = function(obj, start, end, duration) {
    var startTimestamp = null;
    var step = function(timestamp) {
      if (!startTimestamp) startTimestamp = timestamp;
      var progress = Math.min((timestamp - startTimestamp) / duration, 1);
      var easeOut = 1 - Math.pow(1 - progress, 3);
      obj.textContent = Math.floor(easeOut * (end - start) + start);
      if (progress < 1) {
        window.requestAnimationFrame(step);
      } else {
        obj.textContent = end;
      }
    };
    window.requestAnimationFrame(step);
  };

  setTimeout(function() {
    var numberElements = document.querySelectorAll('.score-num[data-val], .mini-val[data-val]');
    numberElements.forEach(function(el) {
      var target = parseInt(el.getAttribute('data-val'));
      if (!isNaN(target)) {
        animateValue(el, 0, target, 2000);
      }
    });
  }, 500);

  // ── Ring Charts Fill Animation ──
  setTimeout(function() {
    var rings = document.querySelectorAll('[data-percent]');
    rings.forEach(function(ring) {
      var percent = parseInt(ring.getAttribute('data-percent'));
      var color = ring.getAttribute('data-color');
      if (isNaN(percent)) return;
      var currentPercent = 0;
      var animateRing = function() {
        currentPercent += (percent - currentPercent) * 0.08;
        ring.style.background = 'conic-gradient(' + color + ' ' + currentPercent + '%, rgba(255,255,255,0.1) 0)';
        if (percent - currentPercent > 0.5) {
          requestAnimationFrame(animateRing);
        } else {
          ring.style.background = 'conic-gradient(' + color + ' ' + percent + '%, rgba(255,255,255,0.1) 0)';
        }
      };
      requestAnimationFrame(animateRing);
    });
  }, 800);

  // ── Pro Max Funnel Animation ──
  setTimeout(function() {
    var funnels = document.querySelectorAll('.f-stage');
    funnels.forEach(function(f) {
      f.style.width = f.getAttribute('data-width');
    });
  }, 1000);

  // ── Parallax Orbs ──
  var orbs = document.querySelectorAll('.bg-orb');
  window.addEventListener('scroll', function() {
    var scrolled = window.scrollY;
    if (orbs[0]) orbs[0].style.transform = 'translateY(' + (scrolled * 0.2) + 'px)';
    if (orbs[1]) orbs[1].style.transform = 'translateY(' + (scrolled * 0.15) + 'px)';
    if (orbs[2]) orbs[2].style.transform = 'translateY(' + (scrolled * -0.1) + 'px) translateX(' + (scrolled * 0.05) + 'px)';
  });

  // ── 3D Tilt Effect on Cards ──
  var cards = document.querySelectorAll('.card');
  cards.forEach(function(card) {
    card.addEventListener('mousemove', function(e) {
      var rect = card.getBoundingClientRect();
      var x = e.clientX - rect.left;
      var y = e.clientY - rect.top;
      var centerX = rect.width / 2;
      var centerY = rect.height / 2;
      var rotateX = ((y - centerY) / centerY) * -5;
      var rotateY = ((x - centerX) / centerX) * 5;
      card.style.transform = 'perspective(1000px) rotateX(' + rotateX + 'deg) rotateY(' + rotateY + 'deg) scale(1.02)';
    });
    card.addEventListener('mouseleave', function() {
      card.style.transform = 'perspective(1000px) rotateX(0deg) rotateY(0deg) scale(1)';
    });
  });
});
