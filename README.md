# Unmasked: The Game (static HTML site)

A plain HTML/CSS/JS rebuild of the Unmasked website (originally a Lovable / TanStack Start React app).
No build step, no framework, no dependencies: open `index.html` or serve the folder from any static host.

## Structure

```
index.html            Home
about.html            About, founder, company details
programme.html        Components, tiers, session walkthrough
tactics.html          The Shield: ten tactics index
tactics/<slug>.html   One page per tactic
editions.html         The six editions
get-involved.html     Four ways to get involved + community sign-up
insight.html          Insight articles
contact.html          Contact details
book.html             Programme enquiry form
find-help.html        Support directory (noindex)
privacy.html          Privacy & safety
404.html              Not found page (GitHub Pages)
assets/css/styles.css All styles (brand tokens at the top)
assets/js/main.js     Quick exit, safety notice, mobile menu, hero animation, forms
assets/img/           Board, cards, founder photo, social images
```

## Safety features

- **Exit** button on every page and **Escape pressed twice** both clear site storage, replace the current
  history entry and send the browser to google.com.
- A first-visit safety notice explains the above. It flips its colours when it sits over a dark band.

## Forms

The enquiry form (`book.html`) and the community sign-up forms (home, get-involved) work without a backend:
on submit they open the visitor's email app with the details addressed to hello@unmasked.ng.

To collect submissions directly instead, create a form endpoint (e.g. Formspree) and add it to the form tag:

```html
<form class="form" data-form data-endpoint="https://formspree.io/f/XXXXXXX" ...>
```

The script then POSTs the form there and shows the confirmation message.

## Deploying (cPanel)

The site is set up for https://unmasked.ng, served from the domain root.

1. In cPanel, run **Security → SSL/TLS Status → Run AutoSSL** so HTTPS works.
2. Upload `unmasked-site.zip` (or the folder contents) to `public_html` and extract it there, so
   `index.html` and `.htaccess` sit directly inside `public_html`.
3. Create the `hello@unmasked.ng` mailbox under **Email Accounts**; the forms address it.

`.htaccess` forces `https://unmasked.ng` (no www), serves the custom `404.html`, allows clean URLs
(`/about` as well as `/about.html`), and enables compression and caching.
`404.html` uses root-relative links (`/…`), so it works at any depth. If the site ever moves to a
sub-folder, update those links and the domain in `.htaccess`, canonical tags and `sitemap.xml`.
