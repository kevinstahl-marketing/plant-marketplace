# Plant Marketplace — Development Handoff

## Project
Drupal 11 marketplace for plants, seeds, fruits, and gardening products.

## Stack
- Drupal 11 + DDEV
- Custom theme: web/themes/custom/marketplace
- Drupal Canvas for visual page building
- Single Directory Components (SDC)
- Shopify integration / Shopify Collective
- Initial checkout through Shopify
- Future: botanical relationships, vendors, Stripe Connect

## Current Progress
- Drupal and DDEV running locally.
- Custom marketplace theme active.
- Design tokens, base styles, and global styles created.
- Canvas hero component working on homepage.
- Header SDC created and rendering Drupal's Header region.
- Branding, Main navigation, and Account menu blocks rendering.
- Twig development mode enabled locally.

## Architecture Decisions
- Keep one Drupal Header region.
- Keep Header as an SDC.
- Drupal Block Layout manages header content and block order.
- Header SDC manages visual presentation and behavior.
- Avoid unnecessary region/block templates.
- Avoid hardcoding block positions or navigation links.
- Keep component CSS/JS inside its component directory.
- Use Canvas for storefront page composition.

## Current Files
- templates/page.html.twig
- components/header/header.twig
- components/header/header.component.yml
- components/header/header.css
- components/header/header.js
- css/tokens.css
- css/base.css
- css/global.css

## Next Steps
1. Polish header styling and responsiveness.
2. Configure actual storefront navigation in Drupal.
3. Build shop/product listing pages.
4. Display Shopify products through Drupal.
5. Connect product purchase actions to Shopify checkout.

## Development Notes
- Twig caching disabled locally.
- CSS/JS aggregation should be disabled for development.
- Twig/CSS edits generally need only browser refresh.
- YAML/component discovery changes may require ddev drush cr.
- No npm watch/build pipeline currently configured.

## Important
Prioritize getting the storefront selling.
Avoid overengineering.
Work incrementally, explaining architecture when needed.
