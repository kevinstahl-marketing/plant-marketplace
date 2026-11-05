
(function (Drupal, once) {
  Drupal.behaviors.marketplaceHeader = {
    attach(context) {
      once('marketplace-header', '.site-header', context)
        .forEach((header) => {
          const updateHeader = () => {
            header.classList.toggle(
              'is-scrolled',
              window.scrollY > 20
            );
          };

          updateHeader();

          window.addEventListener(
            'scroll',
            updateHeader,
            { passive: true }
          );
        });
    }
  };
})(Drupal, once);
