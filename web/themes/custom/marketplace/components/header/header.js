(function (Drupal, once) {
  "use strict";

  Drupal.behaviors.marketplaceHeader = {
    attach(context) {
      once("marketplace-header", ".site-header-area", context).forEach(
        (header) => {
          const toggle = header.querySelector(".site-header__menu-toggle");

          const navigation = header.querySelector("#marketplace-navigation");

          if (!toggle || !navigation) {
            return;
          }

          function setOpen(open) {
            header.classList.toggle("is-menu-open", open);
            toggle.setAttribute("aria-expanded", String(open));
            toggle.setAttribute(
              "aria-label",
              open ? "Close navigation" : "Open navigation",
            );
          }

          const siteHeader = header.querySelector(".site-header");

          const updateHeader = () => {
            siteHeader.classList.toggle("is-scrolled", window.scrollY > 20);
          };

          updateHeader();

          window.addEventListener("scroll", updateHeader, {
            passive: true,
          });

          toggle.addEventListener("click", () => {
            setOpen(toggle.getAttribute("aria-expanded") !== "true");
          });

          document.addEventListener("keydown", (event) => {
            if (event.key === "Escape") {
              setOpen(false);
              toggle.focus();
            }
          });

          document.addEventListener("click", (event) => {
            if (!header.contains(event.target)) {
              setOpen(false);
            }
          });
        },
      );
    },
  };
})(Drupal, once);
