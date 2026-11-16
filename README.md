![Banner](https://rtcamp.com/wp-content/uploads/sites/2/2024/09/OneSearch-Banner.png)

# OneSearch [![Project Status: Active – The project has reached a stable, usable state and is being actively developed.](https://www.repostatus.org/badges/latest/active.svg)](https://www.repostatus.org/#active)

> **OneSearch** is a [OnePress](https://rtcamp.com/onepress/) ecosystem plugin that enables **cross-site, brand-aware search** across a multi-brand WordPress network. It powers unified discovery across sites, powered by [Algolia](https://algolia.com/).

---

## 💡 Why OneSearch?

Managing content across multiple brands, regions, or business units often results in disconnected search experiences. This can lead to broken user journeys and lost discovery opportunities.

**OneSearch** solves this by enabling a **federated search layer** that bridges multiple sites, powered by [Algolia](https://algolia.com/) delivering a consistent and brand-respecting experience across the network.

### Benefits

- **Unified Search Layer:** Execute a single search query across multiple connected sites.
- **Brand Awareness:** Show source brand site and redirect user to the respective site.
- **Governance & Control:** Control visibility and indexing scope at the site or post-type level.
- **Developer Extensibility:** Filters let you adjust the indexed record data, index settings and search parameters.
- **Modular Design:** Extend and customize indexing and search behavior without core overrides.

---

## Getting Started

See the [Setup Guide](docs/guides/setup.md) for detailed installation and configuration instructions.

## Frequently Asked Questions

#### Does OneSearch support CPTs (custom post types)?

Yes. For each brand site, you can select which built-in and custom post types to index from the `Indices and Search` settings.

#### Do I need to manually index a newly published post?

No. Posts are automatically indexed when they are published, and removed when they are deleted or otherwise unpublished (e.g. trashed, changed to `draft`, etc.).

#### Are updates to an already indexed post automatically handled?

Yes. Any updates made to a post are automatically synced with the Algolia index.

#### How are the search results ranked?

Search results are ranked by Algolia's relevance algorithm. However, OneSearch boosts results from the current site you're searching on, ensuring more relevant local content appears first. You can further customize ranking and relevance through Algolia's dashboard.

## Development & Contributing

OneSearch is under active development and maintained by [rtCamp](https://rtcamp.com).

Contributions are welcome and encouraged!

You can join the development and discussions on [GitHub](https://github.com/rtCamp/OneSearch). Feel free to report issues, suggest features, or contribute code.

To learn more about contributing to OneSearch, please read the [Contributing Guide](https://github.com/rtCamp/onesearch/blob/main/docs/CONTRIBUTING.md).

For development guidelines, please refer to our [Development Guide](https://github.com/rtCamp/onesearch/blob/main/docs/DEVELOPMENT.md).
