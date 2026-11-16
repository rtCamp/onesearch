# OneSearch - Setup Guide

This guide will walk you through the steps to set up OneSearch for your WordPress site network, including plugin installation, configuration, and connecting with Algolia for cross-site search functionality.

## Prerequisites

- WordPress 6.9 or later, and PHP 8.2 or later.
- Multiple WordPress sites, either as a WordPress multisite network or multiple standalone installations.
- Administrator access to the network and individual sites.
- An Algolia account with API keys.

## Installation

1. Download the latest OneSearch plugin from the [GitHub releases](https://github.com/rtCamp/OneSearch/releases) and install it on the governing site and brand sites.
2. Activate the plugin.
   For **multisite installations**, make sure to `Network Activate` the plugin.

Stored API keys are encrypted with a key derived from your WordPress salts, so rotating the salts empties them. To keep them when you rotate salts, define `ONEPRESS_ENCRYPTION_KEY` and `ONEPRESS_ENCRYPTION_SALT` in `wp-config.php`. Define them before you add any keys, or enter the keys again after you do.

## Setting Up OneSearch

### Plugin Setup

1. From the `OneSearch > Settings` page, set the Site Type to either 'Governing Site' or 'Brand Site'. This is a one-time selection.
   - **Governing Site:** The central site that manages the brand network and search configurations. There should be only one governing site in the network.
   - **Brand Site:** The individual sites that will be connected to the governing site for cross-site search.
2. On the brand site, navigate to `OneSearch > Settings` to get the API key.
3. On the governing site, navigate to `OneSearch > Settings`, and add the brand site by entering the Site Name, Site URL and the API Key obtained from the brand site.

## Setting Up Algolia

To connect your site with Algolia, follow these simple steps:

1. Visit [Algolia](https://www.algolia.com/) and create an account (if you don't already have one).
2. Go to your [API Keys dashboard](https://dashboard.algolia.com/account/api-keys).
3. Copy the `Application ID` and the `Write API Key`, or create a key restricted to the `onesearch_*` indices with the `search`, `addObject`, `deleteIndex`, `settings` and `editSettings` permissions.
4. On the governing site, paste both keys into the `OneSearch > Settings` page under the 'Algolia Credentials' section.

Brand sites receive this key, so only connect sites you trust with the shared index.

### Configuring Indices and Search Scope

> **Indices**: The data (post types) stored to make site content searchable.
> **Search Scope**: Defines which sites can search or access other sites' indexed data.

#### Configuring Indices

1. On the governing site, head to `OneSearch > Indices and Search`.
2. For each connected site, choose the post types you wish to index.
3. Click on 'Save Changes', which will also index the data.

#### Configuring Search Scopes

1. Go to `OneSearch > Indices and Search` and scroll to the 'Site Search Configuration' section.
2. Turn on the toggle for the sites on which you want to enable OneSearch.
3. In the 'Search from' section, configure which sites can be searched from.
