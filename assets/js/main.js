/* Unmasked — site behaviour: quick exit, safety notice, mobile menu, hero board, forms */
(function () {
  "use strict";

  var root = document.body.getAttribute("data-root") || "";
  var SITE_EMAIL = "hello@unmasked.ng";
  var POPUP_KEY = "unmasked-safety-popup-dismissed";

  /* ---------- Quick exit ---------- */
  function leave() {
    try {
      window.sessionStorage.clear();
      window.localStorage.clear();
      document.cookie.split(";").forEach(function (c) {
        var name = c.split("=")[0].trim();
        if (name) document.cookie = name + "=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/";
      });
    } catch (e) { /* storage or cookies may be blocked */ }

    try {
      // Wash the current page out of this history entry with the neutral home page
      window.history.replaceState(null, "", root + "index.html");
    } catch (e) { /* replaceState may be blocked */ }

    window.location.replace("https://www.google.com");
  }

  document.querySelectorAll("[data-quick-exit]").forEach(function (btn) {
    btn.addEventListener("click", leave);
  });

  // Pressing Escape twice within a second leaves the site
  var escCount = 0;
  var escTimer;
  window.addEventListener("keydown", function (e) {
    if (e.key !== "Escape") return;
    escCount++;
    if (escCount >= 2) leave();
    clearTimeout(escTimer);
    escTimer = setTimeout(function () { escCount = 0; }, 1000);
  });

  /* ---------- Safety notice (first visit) ---------- */
  var popup = document.getElementById("safety-popup");
  if (popup) {
    var dismissed = false;
    try { dismissed = window.localStorage.getItem(POPUP_KEY) === "true"; } catch (e) { /* show anyway */ }

    if (!dismissed) {
      popup.hidden = false;

      // Flip the card's colours when it sits over a dark (brown) band so it stays legible
      var checkBackground = function () {
        if (popup.hidden || typeof document.elementsFromPoint !== "function") return;
        var r = popup.getBoundingClientRect();
        var stack = document.elementsFromPoint(r.left + r.width / 2, r.top + r.height / 2);
        var overDark = false;
        for (var i = 0; i < stack.length; i++) {
          if (popup.contains(stack[i])) continue;
          var band = stack[i].closest("section, footer, header");
          if (band) { overDark = band.hasAttribute("data-dark"); break; }
        }
        popup.classList.toggle("is-over-dark", overDark);
      };
      window.addEventListener("scroll", checkBackground, { passive: true });
      window.addEventListener("resize", checkBackground);
      checkBackground();
      setTimeout(checkBackground, 100);

      popup.querySelectorAll("[data-dismiss]").forEach(function (btn) {
        btn.addEventListener("click", function () {
          try { window.localStorage.setItem(POPUP_KEY, "true"); } catch (e) { /* unavailable */ }
          popup.hidden = true;
        });
      });
    }
  }

  /* ---------- Mobile menu ---------- */
  var toggle = document.querySelector(".menu-toggle");
  var mobileNav = document.getElementById("mobile-nav");
  if (toggle && mobileNav) {
    var setOpen = function (open) {
      mobileNav.hidden = !open;
      toggle.setAttribute("aria-expanded", String(open));
      toggle.textContent = open ? "Close" : "Menu";
    };
    toggle.addEventListener("click", function () { setOpen(mobileNav.hidden); });
    mobileNav.addEventListener("click", function (e) { if (e.target.closest("a")) setOpen(false); });
    window.matchMedia("(min-width: 1200px)").addEventListener("change", function (mq) { if (mq.matches) setOpen(false); });
  }

  /* ---------- Hero board: cards keep dropping onto the board ---------- */
  var stage = document.querySelector("[data-hero-board]");
  if (stage) {
    var img = root + "assets/img/";
    var pool = [
      { src: "card-back.webp", alt: "", weight: 65 },
      { src: "card-front-92.webp", alt: "", weight: 12 },
      { src: "card-front-01.webp", alt: "", weight: 12 },
      { src: "card-front-08.webp", alt: "", weight: 11 }
    ];
    var pick = function () {
      var r = Math.random() * 100, sum = 0;
      for (var i = 0; i < pool.length; i++) { sum += pool[i].weight; if (r <= sum) return pool[i]; }
      return pool[0];
    };
    var addCard = function (src, x, y, spawn, land) {
      var wrap = document.createElement("div");
      wrap.className = "hero-board__card";
      wrap.style.left = x + "%";
      wrap.style.top = y + "%";
      wrap.style.setProperty("--spawn-rot", spawn + "deg");
      wrap.style.setProperty("--land-rot", land + "deg");
      var el = document.createElement("img");
      el.src = img + src;
      el.alt = "";
      el.width = 249;
      el.height = 400;
      el.decoding = "async";
      wrap.appendChild(el);
      stage.appendChild(wrap);
      var cards = stage.querySelectorAll(".hero-board__card");
      if (cards.length > 7) cards[0].remove();
    };

    // Resting cards matching the printed board scatter
    addCard("card-front-92.webp", 23, 9, -15, -10);
    addCard("card-back.webp", 30, 38, -30, -27);
    addCard("card-front-01.webp", 20, 54, -45, -40);
    addCard("card-front-08.webp", 46, 76, 15, 7);

    var reduced = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    var visible = true;
    if ("IntersectionObserver" in window) {
      new IntersectionObserver(function (entries) { visible = entries[0].isIntersecting; }, { threshold: 0.1 }).observe(stage);
    }
    if (!reduced) {
      setInterval(function () {
        if (!visible || document.hidden) return;
        var c = pick();
        // x 20–68% and y 18–72% keep every card on the board
        addCard(c.src,
          Math.round(20 + Math.random() * 48),
          Math.round(18 + Math.random() * 54),
          Math.round(-45 + Math.random() * 90),
          Math.round(-35 + Math.random() * 70));
      }, 2200);
    }
  }

  /* ---------- Forms ----------
     A form with data-endpoint (e.g. a Formspree URL) is POSTed there.
     Without one, the enquiry is handed to the visitor's email app, addressed to the team. */
  document.querySelectorAll("form[data-form]").forEach(function (form) {
    var done = document.getElementById(form.getAttribute("data-done"));
    var error = form.querySelector(".form-error");

    form.addEventListener("submit", function (e) {
      e.preventDefault();
      if (error) error.hidden = true;

      var data = new FormData(form);
      var endpoint = form.getAttribute("data-endpoint");
      var showDone = function (mode) {
        form.hidden = true;
        if (!done) return;
        done.querySelectorAll("[data-mode]").forEach(function (n) { n.hidden = n.getAttribute("data-mode") !== mode; });
        done.hidden = false;
        done.setAttribute("tabindex", "-1");
        done.focus();
      };

      if (endpoint) {
        var submit = form.querySelector("[type=submit]");
        submit.disabled = true;
        fetch(endpoint, { method: "POST", body: data, headers: { Accept: "application/json" } })
          .then(function (res) { if (!res.ok) throw new Error(res.status); showDone("sent"); })
          .catch(function () { if (error) error.hidden = false; })
          .then(function () { submit.disabled = false; });
        return;
      }

      var lines = [];
      form.querySelectorAll("[name]").forEach(function (field) {
        var label = field.closest(".field");
        var name = label ? label.querySelector("span").textContent : field.name;
        if (field.value) lines.push(name + ": " + field.value);
      });
      var subject = form.getAttribute("data-subject") || "Website enquiry";
      window.location.href = "mailto:" + SITE_EMAIL +
        "?subject=" + encodeURIComponent(subject) +
        "&body=" + encodeURIComponent(lines.join("\n"));
      showDone("mail");
    });
  });
})();
