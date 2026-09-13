# How to Install World Graph Studio

World Graph Studio runs as a plugin inside WordPress. The complete installation
also includes its WordPress theme. Installation has four
parts:

1. Create or choose a WordPress site.
2. Install the required Secure Custom Fields plugin.
3. Install and activate World Graph Studio.
4. Install the Frost parent theme, then activate the World Graph Studio theme.

You can complete first-run setup without connecting an outside service or
entering an API key.

## What you need

- WordPress 7.1 or later
- PHP 8.1 or later
- administrator access to WordPress
- an installable World Graph Studio plugin ZIP or the `worldgraph` folder
- the Frost parent theme from the WordPress theme directory
- a World Graph Studio child theme ZIP or the `worldgraph-child` folder
- a current backup if you are using an existing WordPress site

Secure Custom Fields is free and comes from the WordPress plugin directory. It
must be active before World Graph Studio is activated. The World Graph Studio
theme is a child theme, so Frost must be installed before the World Graph
Studio theme can be activated.

## Step 1: Choose where WordPress will run

### WordPress Studio on your computer

WordPress Studio is a free local WordPress application. It is a good choice for
learning World Graph Studio or keeping a project on one computer.

1. Download WordPress Studio from the
   [official Studio page](https://developer.wordpress.com/studio/).
2. Install and open the application.
3. Select **Add site** in the lower-left corner.
4. Select **Build a new site**.
5. Select **Empty site**.
6. Enter a site name, such as `My Story Studio`.
7. Open **Advanced settings**.
8. Select WordPress 7.1 or later and PHP 8.1 or later. You may also set your
   administrator username and password
   here.
9. Select **Add site**.
10. Wait for the status dot beside the site to turn green.
11. Select **WP Admin**. Studio starts the site and opens its WordPress
    dashboard in your browser.
12. Sign in if Studio asks for the administrator credentials.

Continue with [Step 2: Obtain the installation packages](#step-2-obtain-the-installation-packages).
Install the plugins and themes through the WordPress dashboard as described in
Steps 3 through 5.

If ZIP upload is unavailable, select the site in Studio and open its local
folder. Studio sites are stored in the `Studio` folder in the root of your user
directory by default. Copy the `worldgraph` folder to:

`wp-content/plugins/worldgraph/`

The final file must be
`wp-content/plugins/worldgraph/worldgraph.php`. Return to **WP Admin > Plugins
> Installed Plugins** to activate it.

See the official [Studio site instructions](https://developer.wordpress.com/docs/developer-tools/studio/sites/)
for custom locations, domains, HTTPS, and other Studio options.

### Local on your computer

Local is another free desktop application for creating WordPress sites. A Local
account is not required for ordinary sites stored on your computer.

1. Download Local from the
   [official Local page](https://localwp.com/).
2. Install and open Local. On Windows, allow its services through Windows
   Defender Firewall when prompted.
3. Select the **+** button in the lower-left corner.
4. Choose **Create a new site**, then select **Continue**.
5. Enter a site name, such as `My Story Studio`, then select **Continue**.
6. Choose the **Preferred** environment if it uses WordPress 7.1 or later and
   PHP 8.1 or later. Otherwise choose **Custom** and select compatible versions.
7. Enter the WordPress administrator username, password, and email address.
   Save these credentials somewhere secure.
8. Select **Add Site** and wait for Local to finish.
9. Select **Start site** if the site is not already running.
10. Select **WP Admin** and sign in with the WordPress administrator account
    you just created.

Continue with Steps 2 through 5 below. The WordPress dashboard upload method is
the same in Local as it is on a hosted site.

For a folder installation, select **Go to site folder** in Local. Open
`app/public/wp-content/plugins/`, copy the complete `worldgraph` folder there,
then activate it under **WP Admin > Plugins > Installed Plugins**. The final
file must be:

`app/public/wp-content/plugins/worldgraph/worldgraph.php`

Local sites normally remain on your computer. Features such as Live Links,
Connect, and cloud backups are separate from installing World Graph Studio.
See Local's official [installation guide](https://localwp.com/help-docs/getting-started/installing-local/)
for operating-system requirements.

### WordPress on a web host

Choose this path when collaborators or visitors need to reach the site online.
You need a hosting plan that permits custom WordPress plugins. Some managed or
hosted WordPress plans limit plugin uploads, so confirm this before paying for a
plan.

#### Create a new hosted site

1. Confirm with the host that the site can use WordPress 7.1 or later, PHP 8.1
   or later, HTTPS, and custom plugin and theme ZIP uploads.
2. Use the host's control panel to create a WordPress site.
3. Set a strong, unique administrator password and a working administrator
   email address.
4. Enable the host's HTTPS or SSL option.
5. Turn on the host's backup service, or create a full backup before adding
   World Graph Studio to an existing site.
6. Open `https://your-site.example/wp-admin/` and sign in.
7. Continue with Steps 2 through 5 below.

#### Install through the hosted WordPress dashboard

Use **Plugins > Add New Plugin** to install Secure Custom Fields from the
WordPress directory. Then use **Upload Plugin** on the same screen to install
`worldgraph.zip`. Install the two themes under **Appearance > Themes**. The
exact button sequences appear in Steps 3 through 5 below.

If the dashboard has no **Plugins** menu or **Upload Plugin** button, the
account or hosting plan does not currently allow you to install custom plugins.
Ask the host to enable plugin installation or move the site to a plan that
supports it.

#### Install through the host's file manager or SFTP

Use this fallback when the host blocks large ZIP uploads but allows access to
the site files.

1. Extract `worldgraph.zip` on your computer.
2. Open the host's file manager or connect with the SFTP details supplied by
   the host.
3. Locate the WordPress installation. Depending on the host, its web root may
   be named `public_html`, `www`, `htdocs`, or something chosen by the host.
4. Inside that WordPress installation, open `wp-content/plugins/`.
5. Upload the complete `worldgraph` directory.
6. Confirm that the final path ends in
   `wp-content/plugins/worldgraph/worldgraph.php`.
7. Return to **WordPress Admin > Plugins > Installed Plugins**.
8. Activate **World Graph Studio - Story Core**.

Do not upload the plugin into `wp-content/themes/`, `wp-content/uploads/`, or
the web root. Keep the hosted site private or restrict membership while it
contains unpublished material. A self-hosted site is not automatically private.

The standard dashboard and manual upload paths follow the official
[WordPress plugin installation instructions](https://wordpress.org/documentation/article/manage-plugins/).

### With Docker from this repository

This is the supported development path. It requires Git, Docker Engine, and
Docker Compose. Continue at [Developer installation with Docker](#developer-installation-with-docker).

## Step 2: Obtain the installation packages

The package installed by WordPress must have this layout:

```text
worldgraph/
├── worldgraph.php
├── acf-json/
├── assets/
├── includes/
└── plugins/
```

If you received `worldgraph.zip`, open it without extracting it and confirm
that `worldgraph.php` is directly inside its `worldgraph` folder.

The ZIP for the entire source repository is not an installable WordPress
plugin. In the repository, the plugin is located at:

`wordpress/wp-content/plugins/worldgraph/`

To make an uploadable ZIP from a source checkout, run these commands from the
repository root:

```bash
cd wordpress/wp-content/plugins
zip -r worldgraph.zip worldgraph \
  -x 'worldgraph/tests/*' \
  -x 'worldgraph/.DS_Store'
```

This creates `wordpress/wp-content/plugins/worldgraph.zip`. It does not alter
the source folder.

### Prepare the World Graph Studio theme package

Frost is an external parent-theme dependency and is installed from the
WordPress theme directory. The repository contains the World Graph Studio
theme at `wordpress/wp-content/themes/worldgraph-child/`. The child theme uses
Frost as its foundation and adds the layouts, styles, patterns, and public
presentation made for the platform.

If a release includes `worldgraph-theme.zip`, use that file. To create it from
a source checkout, run:

```bash
cd wordpress/wp-content/themes
zip -r worldgraph-theme.zip worldgraph-child \
  -x 'worldgraph-child/.DS_Store'
```

This creates `wordpress/wp-content/themes/worldgraph-theme.zip`.

The folder inside `worldgraph-theme.zip` is named `worldgraph-child`. This is
the correct internal directory name.

## Step 3: Install Secure Custom Fields

From the WordPress dashboard:

1. Open **Plugins > Add New Plugin**.
2. Enter `Secure Custom Fields` in the search box.
3. Find **Secure Custom Fields (SCF)**.
4. Select **Install Now**.
5. When installation finishes, select **Activate**.
6. Open **Plugins > Installed Plugins** and confirm that it is marked
   **Active**.

Do not continue until it is active. World Graph Studio uses it for the
structured information attached to Projects, Characters, Locations, Scenes,
Shots, Assets, and the other parts of the Story Graph.

## Step 4: Install World Graph Studio

### Install from a ZIP

1. Open **Plugins > Add New Plugin**.
2. Select **Upload Plugin** near the top of the page.
3. Select **Choose File** and choose `worldgraph.zip`.
4. Select **Install Now**.
5. Wait for WordPress to report that installation succeeded.
6. Select **Activate Plugin**.

WordPress should take an administrator to the setup screen. If it does not,
open **World Graph Studio > Setup & Settings**.

### Install from the plugin folder

Use this method when your local WordPress application or host gives you access
to the site's files.

1. Locate the site's `wp-content/plugins/` directory.
2. Copy the complete `worldgraph` folder into it.
3. Confirm that the final path is
   `wp-content/plugins/worldgraph/worldgraph.php`.
4. Sign in to WordPress.
5. Open **Plugins > Installed Plugins**.
6. Find **World Graph Studio - Story Core** and select **Activate**.

Avoid an extra folder level such as
`wp-content/plugins/worldgraph/worldgraph/worldgraph.php`.

## Step 5: Install the World Graph Studio theme

Install Frost first. WordPress must be able to find the parent theme before it
can activate the World Graph Studio child theme.

### Install the themes from the WordPress dashboard

1. In WordPress, open **Appearance > Themes**.
2. Select **Add New Theme**.
3. Search for `Frost` and select **Install** on the Frost theme. Frost only
   needs to be installed; do not activate it unless you want to inspect it.
4. Select **Upload Theme**.
5. Choose `worldgraph-theme.zip` and select **Install Now**.
6. When installation finishes, select **Activate** for **World Graph Studio**.
7. Open the public site and confirm that the World Graph Studio design appears.

### Install the themes from folders

Install Frost from **Appearance > Themes > Add New Theme** first. Then copy the
complete World Graph Studio theme folder into `wp-content/themes/`. The final
paths must be:

```text
wp-content/themes/frost/style.css
wp-content/themes/worldgraph-child/style.css
```

Frost is downloaded by WordPress into the first path. For Local, these paths
begin inside `app/public/`. For WordPress Studio,
they begin inside the Studio site's selected directory. For a hosted site,
upload both folders with the host's file manager or SFTP.

After copying them, open **Appearance > Themes** in WordPress and activate
**World Graph Studio**. Do not rename either theme folder; the child theme
looks specifically for the parent directory named `frost`.

The plugin can store and manage Story Graph data with another WordPress theme,
but the World Graph Studio theme provides the intended public project pages,
navigation, block patterns, and visual design.

## Step 6: Complete first-run setup

Open **World Graph Studio > Setup & Settings**. The page includes optional
settings for media generation and language tools. Neither is required to begin
using the Story Graph.

For a first installation without outside services:

1. Choose the option for no generation Connection.
2. Leave generation credentials and endpoint fields empty.
3. Leave language-model credentials empty if you do not need writing
   assistance or document decomposition yet.
4. Leave the advanced values at their defaults.
5. Save the setup form.

Saving marks the one-time setup as complete. You can return later through
**Setup & Settings** or **World Graph Studio > Connections**.

Only add a hosted Connection after checking what information it receives, how
it stores that information, and what it charges. A consumer subscription does
not necessarily include API access.

## Step 7: Verify the installation

1. Open **Plugins > Installed Plugins**. Both **Secure Custom Fields** and
   **World Graph Studio - Story Core** should be active.
2. Open **Appearance > Themes**. Frost should be installed and **World Graph
   Studio** should be the active theme.
3. Confirm that **World Graph Studio** appears in the dashboard menu.
4. Open the World Graph Studio dashboard without an error.
5. Confirm that screens are available for Projects, Characters, Locations,
   Scenes, Shots, Assets, Import, and Export.
6. Open **World Graph Studio > Import** and confirm that Story Import & Export
   is available.

For an end-to-end check, follow the [sample project guide](sample-projects.md).
Canonical World Graph Studio JSON does not require a language-model Connection.

## Developer installation with Docker

Run these commands from the repository root.

### 1. Create the local settings file

```bash
cp .env.example .env
```

The default site binds to `127.0.0.1:8080`, so it is reachable only from the
development computer. Change the example database passwords in `.env` before
using the environment for anything beyond disposable local development.

### 2. Build and start WordPress and MariaDB

```bash
docker compose up -d --build
docker compose ps
```

Wait until the `wordpress` and `database` services are healthy. The first build
can take several minutes.

### 3. Install WordPress

Replace the example administrator name, password, and email:

```bash
docker compose exec wordpress wp core install \
  --url=http://localhost:8080 \
  --title="World Graph Studio" \
  --admin_user=studio_admin \
  --admin_password='replace-this-with-a-strong-password' \
  --admin_email='you@example.com'
```

If you changed `WORDPRESS_PORT` in `.env`, use that port in `--url`.

### 4. Install the required plugin

```bash
docker compose exec wordpress wp plugin install secure-custom-fields --activate
```

### 5. Activate World Graph Studio

The repository mounts the source plugin into WordPress, so no upload is needed:

```bash
docker compose exec wordpress wp plugin activate worldgraph
```

### 6. Activate the World Graph Studio theme

Install Frost from the WordPress theme directory, then activate the child theme
that Docker Compose mounts from the repository:

```bash
docker compose exec wordpress wp theme install frost
docker compose exec wordpress wp theme activate worldgraph-child
```

The first command should report that Frost is installed. The second should
report that the World Graph Studio theme was activated.

### 7. Confirm the plugins and theme are active

```bash
docker compose exec wordpress wp plugin status secure-custom-fields
docker compose exec wordpress wp plugin status worldgraph
docker compose exec wordpress wp theme status worldgraph-child
```

All three commands should report `Status: Active`.

### 8. Finish setup in the browser

Open `http://localhost:8080/wp-admin/`, sign in with the administrator account
created above, and complete **World Graph Studio > Setup & Settings**. The
headless frontend, phpMyAdmin, ComfyUI, and outside Connections are not required
for the core installation.

Stop the containers without deleting saved data:

```bash
docker compose stop
```

Start them again with:

```bash
docker compose up -d
```

## Common installation problems

### “World Graph Studio requires Secure Custom Fields”

Secure Custom Fields is missing or inactive. Activate it under **Plugins >
Installed Plugins**, then activate World Graph Studio again.

### “The package could not be installed. No valid plugins were found”

The wrong ZIP was uploaded. Do not upload the entire repository ZIP. Use a ZIP
whose top-level `worldgraph` folder contains `worldgraph.php`.

### World Graph Studio is not listed on the Plugins screen

Check that the final path is
`wp-content/plugins/worldgraph/worldgraph.php`. Remove any accidental extra
directory level and reload the Plugins screen.

### The site reports an unsupported PHP version

Ask the host to switch the site to PHP 8.1 or later. In a desktop WordPress
application, change the site's PHP version in its environment settings when
that option is available.

### Upload fails because the ZIP is too large

Increase the site's PHP upload limit, ask the host for help, or copy the
uncompressed `worldgraph` folder into `wp-content/plugins/` through the host's
file manager or SFTP.

### Activation succeeds but setup does not open

Open **World Graph Studio > Setup & Settings** manually. Confirm that you are
signed in with an administrator account.

### “The parent theme is missing” or “Broken Themes”

Frost is missing or its folder was renamed. Install the bundled Frost theme and
confirm that its file is at `wp-content/themes/frost/style.css`. Then return to
**Appearance > Themes** and activate **World Graph Studio** again.

### Docker reports that port 8080 is already in use

Edit `.env`, change `WORDPRESS_PORT=8080` to an unused port such as
`WORDPRESS_PORT=8082`, restart the stack, and use the new port in the browser
URL and the `wp core install --url` value.

### Docker says WordPress is already installed

The named Docker volumes already contain a WordPress site. Open the existing
site instead of running `wp core install` again. Removing volumes deletes the
local database and uploaded files, so do not do that unless you deliberately
want a fresh, disposable installation.

## After installation

Continue with [Getting Started](getting-started.md), then import the
[sample project](sample-projects.md). Optional services can be added later from
the [integration guide](../about/marketing/integrations.md).

Site operators can find details about upgrades, scheduled jobs, credentials,
provider setup, and production deployment in the
[operator setup guide](../wordpress/wp-content/plugins/worldgraph/documentation/SETUP_GUIDE.md).
