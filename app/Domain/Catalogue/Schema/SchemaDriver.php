<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Schema;

use App\Domain\Catalogue\Models\Product;
use App\Domain\Catalogue\Models\ProductVariant;

/**
 * What a storefront's products look like, and how to send one.
 *
 * ── The problem this solves ──────────────────────────────────────────────────
 *
 * Every platform describes a product differently. Shopify has variants and
 * option1/2/3; WooCommerce has attributes and meta_data; a shop somebody wrote
 * themselves has whatever its developer chose that afternoon. Hardcoding each
 * one means the product form grows a branch per platform, and supporting a new
 * one is a release.
 *
 * So the tool never learns what a Shopify product looks like. It asks a driver
 * for a list of FieldDefinitions, renders those, and hands the values back to
 * the same driver to be turned into whatever that platform wants on the wire.
 * Adding a platform is adding one class.
 *
 * ── Why discovery reads a real product ───────────────────────────────────────
 *
 * A driver for a known platform can answer from what it already knows. A driver
 * for somebody's own API cannot, so it reads one real product back and learns
 * the shape from it. That is also the only honest way to handle a known
 * platform that has been extended — a Shopify store with six metafields is not
 * the Shopify in the documentation.
 *
 * Which is why sampleVia() exists. "These are the fields we read from your
 * store" and "these are the fields this platform usually has" look identical on
 * screen and mean very different things, and somebody debugging a failed sync
 * needs to know which they are looking at.
 *
 * ── Ported, not copied ───────────────────────────────────────────────────────
 *
 * The first Prism had this idea and it was the best thing in its catalogue. Two
 * changes here: it deals in variants, because the platforms do and the old
 * model could not; and it takes no Store model, because storefronts do not
 * exist yet and this contract should not wait for them — a driver is handed the
 * connection details it needs rather than reaching for a table.
 */
interface SchemaDriver
{
    /** Platform slugs this driver claims, lowercase. */
    public static function handles(): array;

    /** Shown when somebody is choosing how a storefront connects. */
    public function label(): string;

    /**
     * The fields this storefront's products carry.
     *
     * @param  array<string, mixed>  $connection  endpoint, credentials, options
     * @return list<FieldDefinition>
     */
    public function discover(array $connection): array;

    /**
     * The same, for a variant rather than the product as a whole.
     *
     * Separate because the split differs by platform: Shopify puts price and
     * barcode on the variant, some others put them on the product and only vary
     * a label. A driver that could not say which would force the form to guess.
     *
     * @param  array<string, mixed>  $connection
     * @return list<FieldDefinition>
     */
    public function discoverVariantFields(array $connection): array;

    /**
     * Where a sample product can be read from, so discovery has something to
     * learn from and the connection can be proved before anything is pushed.
     *
     * @param  array<string, mixed>  $connection
     */
    public function sampleEndpoint(array $connection): ?string;

    /**
     * How the last discovery got its sample: 'api', 'webhook', or null for
     * none — meaning the answer came from what the driver already believed.
     */
    public function sampleVia(): ?string;

    /**
     * Turn a product and its variants into the body this platform expects.
     *
     * @param  list<ProductVariant>  $variants
     * @param  array<string, mixed>  $values  the storefront-specific values
     * @return array<string, mixed>
     */
    public function buildPayload(Product $product, array $variants, array $values): array;

    /**
     * Read a platform's own product back into the shape Prism understands.
     *
     * The direction that makes an import possible, and the one the first Prism
     * never had — it could push but not pull, so a storefront with a thousand
     * existing products had to be typed in again.
     *
     * @param  array<string, mixed>  $remote
     * @return array{product: array<string, mixed>, variants: list<array<string, mixed>>}
     */
    public function parsePayload(array $remote): array;

    /**
     * Where a product is created or updated on this platform.
     *
     * @param  array<string, mixed>  $connection
     */
    public function writeEndpoint(array $connection, ?string $externalId = null): ?string;
}
