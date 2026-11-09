# Plant Marketplace — Catalog Development Handoff

Date: November 9, 2026
Branch: feat/catalog

## Project goal

Build a discovery-first Drupal plant marketplace featuring
rare plants, seeds, cuttings, fruit trees, botanical species,
cultivars, growing zones, and related products.

Initial supplier integration: Shopify Collective.
Future marketplace: independent sellers with Stripe Connect.

## Current architecture

- Drupal 11 with custom marketplace theme
- DDEV local development
- Drupal Canvas and Single Directory Components
- Search API with database backend
- Custom Shopify connector
- Shopify OAuth and Admin GraphQL
- Shopify handles Collective checkout and fulfillment

## Work completed

- Shopify OAuth integration previously tested.
- Shopify GraphQL queries previously tested.
- Shopify Collective checkout and supplier fulfillment tested.
- Shopify API client updated for automatic token refresh.
- Collective products added to Shopify.
- Shopify product CSV exported.
- Botanical enrichment workbook prepared.

## Catalog data

Previous enrichment work identified approximately:
- 63 products
- 89 variants
- 29 species candidates
- 15 cultivar candidates

These are preliminary counts and botanical classifications.
Review and verify the workbook before importing.

The enriched workbook was delivered in ChatGPT.
It is not automatically part of the Git repository.

## Architecture decision

Use Drupal Commerce as the canonical product catalog.

For initial Shopify Collective products:
- Drupal Commerce stores the local product representation.
- Shopify remains responsible for checkout and fulfillment.
- Shopify IDs are preserved for synchronization.
- Prices, availability, and variants synchronize from Shopify.

For future independent sellers:
- Reuse Commerce product infrastructure.
- Add seller ownership and marketplace permissions.
- Implement Commerce checkout and Stripe Connect integration.
- Multi-vendor payment routing is NOT built yet.

## Botanical data model

Keep botanical identity separate from commercial products.

Species:
- Scientific name
- Genus
- Family
- Common names
- Botanical information

Cultivars:
- Cultivar name
- Required species relationship

Products:
- Commerce product and variations
- Shopify product/variant identifiers
- Supplier/source
- Product form: seed, cutting, grafted tree, etc.
- Species and optional cultivar relationships
- Collection/category relationships

Multiple supplier products may reference the same species
and cultivar records.

## Important decisions

- Do not treat every Shopify CSV row as a separate product.
- Do not merge distinct supplier listings automatically.
- Preserve original supplier data and Shopify IDs.
- Flag uncertain botanical identifications for review.
- Do not invent hardiness zones or cultivar identities.
- Do not build custom checkout for Collective products.
- Keep storefront presentation separate from product data.

## Next session

1. Check Git status and current branch.
2. Verify Drupal, PHP, and Commerce compatibility.
3. Install Drupal Commerce 3.x if compatible.
4. Enable the necessary Commerce product modules.
5. Define initial Commerce product and variation types.
6. Design species, cultivar, and product-form relationships.
7. Map enriched workbook columns to Drupal fields.
8. Build a repeatable Shopify product importer.
9. Test with a small batch before importing everything.
10. Build product cards, collections, and Search API integration.

Do not configure payment gateways, Stripe Connect,
or Commerce checkout yet.

## First command next session

cd ~/plant-marketplace

ddev start

git status --short
git branch --show-current

ddev drush status --fields=drupal-version
ddev exec php -v | head -1
ddev composer show drupal/commerce 2>/dev/null || true

## Resume instruction for ChatGPT

Read docs/CATALOG_HANDOFF.md.

Continue the Drupal Plant Marketplace catalog implementation
from the current Git state. Work incrementally, provide
copy/paste DDEV commands, and verify each step before proceeding.

Start by checking Drupal Commerce compatibility.
