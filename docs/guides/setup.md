# OneSearch - Setup Guide

This guide will walk you through the steps to set up OneSearch for your WordPress site network, including plugin installation, configuration, and connecting with Algolia for cross-site search functionality.

## Prerequisites

- Multiple WordPress sites, either as a WordPress multi-site network or two standalone installations.
- Administrator access to the network and individual sites.
- An Algolia account with API keys.

## 🧰 Installation

1. Download the latest OneSearch plugin from the [GitHub releases](https://github.com/rtCamp/OneSearch/releases) and install it on your WordPress sites.
2. Activate the plugin.
   For **multisite installations**, make sure to `Network Activate` the plugin.
3. Visit the `OneSearch > Settings` page to configure the Governing and Brand sites.

## Setting Up OneSearch

### Plugin Setup

1. Install and activate the OneSearch plugin on the governing site and brand sites.
2. From the `OneSearch > Settings` page, set the Site Type to either 'Governing Site' or 'Brand Site'.
   - **Governing Site:** The central site that manages the brand network and search configurations. There should be only one governing site in the network.
   - **Brand Site:** The individual sites that will be connected to the governing site for cross-site search.
3. On the brand site, navigate to `OneSearch > Settings` to get the API key.
4. On the governing site, navigate to `OneSearch > Settings`, and add the brand site by entering the Site name, URL and the API Key obtained from the brand site.

## Setting up Algolia

To connect your site with Algolia, follow these simple steps:

1. Visit [Algolia](https://www.algolia.com/) and create an account (if you don't already have one).
2. Go to your [API Keys dashboard](https://dashboard.algolia.com/account/api-keys).
3. Copy the `Application ID` and `Write API` Key.
4. Paste both keys into the `OneSearch > Settings` page under the 'Algolia Credentials' section.

### Configuring Indices and Search Scope

> **Indices**: The data (post types) stored to make site content searchable.
> **Search Scope**: Defines which sites can search or access other sites' indexed data.

#### Configuring Indices

1. Head to OneSearch > Indices and Search.
2. Against each connected site, choose the post types you wish to index.
3. Click on 'Save' which will also index the data.

#### Configuring Search Scopes:

1. Go to OneSearch > Indices and Search and scroll to 'Site Search Configuration' section.
2. Turn on the toggle for the sites on which you want to enable OneSearch.
3. In the 'Search from' section, configure which sites can be searched from.
