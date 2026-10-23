# InvoiceShelf development environment

The repository includes a Docker Compose development stack for Linux and macOS.
It provides PHP 8.4, nginx, your chosen database, Adminer, and Mailpit. An
optional Gotenberg service is available for PDF development.

> This stack is for development only. For a production installation, use the
> [official Docker repository](https://github.com/InvoiceShelf/docker).

## Start the stack

You need Git, Docker, and Docker Compose. Clone your fork, prepare the local
environment file, and run the interactive helper from the repository root:

```bash
git clone git@github.com:YOUR-USERNAME/InvoiceShelf.git
cd InvoiceShelf
cp .env.example .env
./devenv
```

The helper:

1. checks Docker and Docker Compose;
2. adds `invoiceshelf.test` to `/etc/hosts` when needed;
3. asks you to choose MySQL/MariaDB, PostgreSQL, or SQLite;
4. optionally enables the Gotenberg PDF service; and
5. saves that choice in `.devenvconfig` for later commands.

On the first run, install the PHP dependencies inside the running container:

```bash
./devenv run composer install
./devenv run php artisan key:generate
```

The frontend runs on the host with Node.js 24 and the pnpm version pinned in
`package.json`:

```bash
corepack enable
pnpm install --frozen-lockfile
pnpm dev
```

Keep `pnpm dev` running while you work. Open <http://invoiceshelf.test> and
complete the installation wizard. For
MySQL or PostgreSQL, use `db` as the database host and `invoiceshelf` as the
database name, username, and password. For SQLite, keep the wizard's default
`storage/app/database.sqlite` path.

## Everyday commands

```bash
./devenv start              # Start the saved stack
./devenv stop               # Stop it
./devenv logs               # Follow service logs
./devenv shell              # Open a shell in the PHP container
./devenv run php artisan about
./devenv test               # Run Pest
./devenv format             # Run Pint
./devenv rebuild            # Rebuild images from scratch
```

`./devenv destroy` removes the stack, its images, and its database volumes. The
command asks for confirmation before deleting anything.

## Services

| Service | Address |
| --- | --- |
| InvoiceShelf | <http://invoiceshelf.test> |
| Adminer | <http://localhost:8080> |
| Mailpit | <http://localhost:8025> |

Mailpit receives development email over SMTP at `mail:1025`. Adminer connects
to MySQL or PostgreSQL at host `db`; its database, username, and password are
all `invoiceshelf`.

## Gotenberg version

The Gotenberg-enabled Compose files pin `gotenberg/gotenberg:8.36` instead of
the floating `:8` tag. ZUGFeRD e-invoicing needs the `facturxXml` field that
Gotenberg added in 8.34.0, so the dev stack must not silently run an older 8.x
image. Keep the pin at 8.34 or newer when bumping it.

## Checking a Hybrid PDF by hand

With the Gotenberg-enabled stack running, set the instance PDF driver to
Gotenberg (**Admin → PDF Generation**), enable E-Invoicing and fill in the bank
details for the company (**Settings → E-Invoice**), then download any invoice
whose data the readiness indicator accepts. The downloaded file is a Hybrid PDF:

```bash
# The embedded e-invoice is a PDF attachment named factur-x.xml
pdfdetach -list invoice.pdf

# Extract and read it
pdfdetach -save 1 -o factur-x.xml invoice.pdf && head factur-x.xml
```

`pdfdetach` ships with poppler-utils. An invoice the builder reports missing
requirements for downloads as an ordinary PDF with no attachment — that is the
Fallback, not a failure.

## Advanced Compose usage

The six Compose definitions in this directory cover all three databases, with
and without Gotenberg. The `./devenv` helper is the supported entry point, but
you can inspect or run those files directly when debugging the stack.

Run `./devenv --help` for the complete command list.
