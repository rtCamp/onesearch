=== OneSearch ===
Contributors: rtcamp, shreya0204, danish17, vishalkakadiya, rishavjeet, vishal4669, up1512001, justlevine, aviral-mittal
Donate link: https://rtcamp.com/
Tags: OnePress, OneSearch, Cross-site search, Multi-brand network, WordPress multisite, Federated search, Algolia
Requires at least: 6.8
Tested up to: 6.9
<!-- x-release-please-start-version -->
Stable tag: 1.1.0
<!-- x-release-please-end -->
Requires PHP: 8.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Cross-site, brand-aware search across multi-brand WordPress networks, powered by Algolia.

== Description ==

OneSearch is part of the OnePress ecosystem, designed to enable cross-site search across multi-brand WordPress networks. It centralizes the discovery process, allowing users to search and retrieve relevant content from multiple connected sites from whichever site(s) in your brand network you choose to make searchable.

This plugin acts as the backend engine that powers the indexing, querying, brand network management, and filtering logic needed for federated search.

**Why OneSearch?**

Managing content across multiple brands, regions, or business units often results in disconnected search experiences. This can lead to broken user journeys and lost discovery opportunities.

OneSearch solves this by enabling a federated search layer that bridges multiple sites, powered by Algolia, delivering a consistent and brand-respecting experience across the network.

**Key Benefits:**

* **Unified Search Layer:** Execute a single search query across multiple connected sites
* **Brand Awareness:** Show source brand site and redirect users to the respective site
* **Governance & Control:** Control visibility and indexing scope at the site or post-type level
* **Developer Extensibility:** Easily register post types, taxonomies, and metadata for indexing
* **Performance Optimized:** Lightweight REST architecture with cache-friendly responses
* **Modular Design:** Extend and customize indexing and search behavior without core overrides

**Core Features:**

* **Cross-Site (Federated) Search:** Aggregate search results across multisite or standalone installations
* **Configurable Indexing:** Register which post types, taxonomies, or meta fields are searchable on a per-site basis
* **Custom Blocks:** Gutenberg-ready blocks for search interfaces
* **Bring Your Own Key:** Connect with your Algolia instance for improved data sovereignty and reduced vendor lock-in
* **Automatic Indexing:** Posts are automatically indexed on publish and removed on deletion
* **Local Content Boost:** Search results prioritize content from the current site while including network-wide results

**Perfect for:**

* Enterprise WordPress deployments with multiple brand sites
* Organizations requiring unified content discovery across sites
* Companies with multi-region or multi-brand content strategies
* Agencies managing multiple client sites with shared content
* Media networks needing cross-publication search capabilities

== Installation ==

1. Download the latest OneSearch plugin from the GitHub releases and install it on your WordPress sites
2. Activate the plugin through the 'Plugins' screen in WordPress
   * For multisite installations, make sure to Network Activate the plugin
3. **Choose Site Type (One-time selection):**
   * **Governing Site:** Central site that manages the brand network and search configurations (one per network)
   * **Brand Site:** Individual sites connected to the governing site for cross-site search
4. **For Brand Sites:** Navigate to Dashboard → OneSearch → Settings to get the API key
5. **For Governing Site:** Go to Dashboard → OneSearch → Settings and add Brand Sites by entering Site name, URL, and the API Key obtained from each Brand Site

**Setting up Algolia:**

1. Visit Algolia (https://www.algolia.com/) and create an account if you don't have one
2. Go to your API Keys dashboard in Algolia
3. Copy the Application ID and Write API Key
4. Paste both keys into Dashboard → OneSearch → Settings under the 'Algolia Credentials' section

**Configuring Indices and Search Scope:**

1. Navigate to OneSearch → Indices and Search on the Governing Site
2. For each connected site, choose which post types to index
3. Click 'Save' to save settings and index the data
4. In the 'Site Search Configuration' section, enable OneSearch on desired sites
5. Configure which sites can be searched from each enabled site

== Frequently Asked Questions ==

= Does OneSearch support CPTs (custom post types)? =

Yes. For each brand site, you can select which built-in and custom post types to index from the Indices and Search settings.

= Do I need to manually index a newly published post? =

No. Posts are automatically indexed when they are published, and removed when they are deleted or otherwise unpublished (e.g. trashed, changed to draft, etc.).

= Are updates to an already indexed post automatically handled? =

Yes. Any updates made to a post are automatically synced with the Algolia index.

= How are the search results ranked? =

Search results are ranked by Algolia's relevance algorithm. However, OneSearch boosts results from the current site you're searching on, ensuring more relevant local content appears first. You can further customize ranking and relevance through Algolia's dashboard.

= Do I need my own Algolia account? =

Yes. OneSearch uses a "Bring Your Own Key" approach, connecting to your Algolia instance for improved data sovereignty and reduced vendor lock-in.

= Can I control which sites are searchable from each brand site? =

Yes. From the Governing Site, you can configure the search scope for each brand site, controlling which sites' content appears in search results.

== Screenshots ==

@todo - Screenshots need to be captured and added before release.

== Changelog ==

See <a href="https://github.com/rtCamp/OneSearch/blob/main/CHANGELOG.md" target="_blank">CHANGELOG.md</a> for detailed changelog.

== Support ==

For support, feature requests, and bug reports, please visit our [GitHub repository](https://github.com/rtCamp/OneSearch).

== Contributing ==

OneSearch is open source and welcomes contributions. Visit our [GitHub repository](https://github.com/rtCamp/OneSearch) to contribute code, report issues, or suggest features.

Development guidelines and contributing information can be found in our repository documentation.
