# ERPGulf AI Translate
**Version:** 1.0.0  
**Author:** Farook K — https://medium.com/nothing-big  
**Requires:** WordPress 6.0+, WooCommerce 7.0+, WPML, PHP 8.1+  
**Support:** support@erpgulf.com

---

## What This Plugin Does

Translates WooCommerce products from Arabic to English using AI — Gemini, ChatGPT, or Claude. Saves translations directly into WPML English product versions. **Zero WPML credits used.**

---

## Files

```
erpgulf-woo-ai-translator/
├── erpgulf-woo-ai-translator.php   ← main plugin file
├── gemini-provider.php             ← Google Gemini provider
├── openai-provider.php             ← OpenAI ChatGPT provider
└── claude-provider.php             ← Anthropic Claude provider
```

---

## Requirements

| Requirement | Details |
|---|---|
| WordPress | 6.0 or higher |
| WooCommerce | 7.0 or higher |
| WPML | With WooCommerce Multilingual |
| PHP | 8.1 or higher |
| API Key | Google Gemini (free), OpenAI, or Anthropic |

---

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate the plugin in WordPress Admin → Plugins
3. Go to **ERPGulf AI Translate** in the admin menu
4. Enter your API key for your chosen AI provider
5. Select which fields to translate
6. Save settings

---

## Quick Start

### Single product
1. Open any Arabic product in the WordPress admin
2. Find the **🤖 ERPGulf AI Translate** box in the right sidebar
3. Click **Translate to English**
4. Done — the English product is created or updated automatically

### Bulk translate
1. Go to **ERPGulf AI Translate → Bulk Translate**
2. All untranslated Arabic products are listed with checkboxes
3. Select the products you want (Select All is checked by default)
4. Click **▶ Start**
5. Watch the live progress — each product shows ✅ or ❌ as it completes
6. Use **⏸ Pause** or **⏹ Stop** at any time

---

## AI Providers

### Google Gemini (recommended — free)
- **Free tier** available at aistudio.google.com
- No billing required for Flash models
- Get key: https://aistudio.google.com/app/apikey
- Models: Gemini 2.0 Flash, 1.5 Flash, Flash Lite
- Models are fetched live from the API — always up to date

### OpenAI ChatGPT
- Requires a paid OpenAI account
- Get key: https://platform.openai.com/api-keys
- Models: GPT-4o Mini (recommended), GPT-4o, GPT-3.5 Turbo

### Anthropic Claude
- Requires an Anthropic account
- Get key: https://console.anthropic.com/settings/keys
- Models: Claude Haiku (recommended), Claude Sonnet

---

## Settings Page

**Admin → ERPGulf AI Translate**

### Active AI Provider
Select which AI handles translations. All credentials are saved — only the selected provider is used.

### Translation Direction
```
Source Language: Arabic    ← language products are written in
Target Language: English   ← must match your WPML language name
```

### Fields to Translate
Three groups of fields are auto-discovered from your products:

**📝 Standard Fields**
- Product Title
- Product Description
- Short Description

**⚙️ Custom & ACF Fields**
- Manufacturer Brand
- Any other custom text fields found in your products

**🔁 Repeater Fields**
- Compatibility (add_compactable_details) — translates brand, copies model/years as-is
- Any other ACF repeater fields found in your products

### Tools & Maintenance

**♻️ Regenerate Lookup Table**  
Fixes admin SKU search for all translated products. Run once after bulk translation.

**🔄 Sync All Translated Products**  
For existing translated products, copies any missing fields without using API calls:
- Compatibility data
- Branch stock
- Product images
- Price
- SKU
- Categories

Safe to run anytime. Does not overwrite translated text.

---

## What Gets Translated vs Copied

### Translated by AI
```
Product title          Arabic → English via AI
Product description    Arabic → English via AI
Short description      Arabic → English via AI
Custom text fields     Arabic → English via AI (e.g. Manufacturer Brand)
Repeater brand field   Arabic → English via AI
Product categories     Translated and created if missing in WPML
```

### Copied as-is (first translation only)
```
Regular price          Copied once — WPML syncs changes after
Sale price             Copied once — WPML syncs changes after
Stock quantity         Copied once — WPML syncs changes after
Stock status           Copied once — WPML syncs changes after
Weight / Dimensions    Copied once — WPML syncs changes after
SKU                    Copied — same across languages
Product image          Copied — same image shown in both languages
Gallery images         Copied — same images shown in both languages
WooSB bundle IDs       Copied
```

### Auto-synced in real time (no action needed)
```
Price changes          WPML Multilingual for WooCommerce syncs automatically
Stock changes          WPML Multilingual for WooCommerce syncs automatically
Branch stock changes   Our plugin auto-syncs via WordPress meta hooks
```

### Not touched
```
Password              Not applicable
Order data            Not applicable
Customer data         Not applicable
```

---

## Product Meta Box

On every Arabic product edit page, the **🤖 ERPGulf AI Translate** sidebar box shows:

**States:**

```
⚙️ This is the EN version. Open the Arabic product to translate.
   (shown on English products — button hidden)

⚠️ No English version yet — will be created.
   [🤖 Translate to English]

✅ English version exists  ·  View English →
   [🤖 Translate to English]

🗑️ English version is in Trash
   [ ♻️ Restore ]  [ 🗑️ Delete ]
   [🤖 Translate to English]
```

---

## Bulk Translate Page

**Admin → ERPGulf AI Translate → Bulk Translate**

Shows only untranslated Arabic products (those with no English version in WPML).

### Stats bar
```
┌──────────┐  ┌──────────┐  ┌──────────┐
│   738    │  │    0     │  │    0     │
│Untranslated│ │Translated│  │  Failed  │
└──────────┘  └──────────┘  └──────────┘
```

### Controls
| Button | Action |
|---|---|
| ▶ Start | Begin translating selected products one by one |
| ⏸ Pause | Pause after current product finishes |
| ▶ Resume | Continue from where paused |
| ⏹ Stop | Stop after current product — already translated items are saved |

### Product list
Each product shows:
- Checkbox (checked by default)
- Arabic product name
- ID and SKU

Status icons update live:
- 🔄 Currently translating
- ✅ Successfully translated
- ❌ Failed

### Live log
Shows in real time:
```
🔄 [ID:281054 SKU:B003000001] ذراع التحكم في المسار BMW...
✅ [ID:281054 SKU:B003000001] BMW Track Control Arm Left Rear
❌ [ID:281099 SKU:B003000002] ذراع التحكم — API timeout
⏸ Paused — 450 remaining
▶ Resumed
⏹ Stopped. 43 translated · 2 failed
```

---

## Branch Stock

Products use an ACF repeater field for per-branch inventory:
```
branch_stock                 ← row count
branch_stock_0_branch        = jeddah-branch
branch_stock_0_stock_qty     = 5
```

**On first translation** — branch stock is copied to the English product.

**Day to day** — whenever branch stock changes on the Arabic product, the plugin automatically copies the change to the English version in real time. No manual action needed.

---

## Categories

### Existing WPML categories
If the Arabic product category already has an English translation in WPML, it is assigned automatically.

### Missing category translations
If no English version exists, the plugin:
1. Translates the category name via AI
2. Creates the English category in WordPress
3. Links Arabic and English versions in WPML
4. Assigns the new category to the English product

This happens automatically for both `product_cat` and `offer_category` taxonomies.

---

## Developer Hooks

### Filters

#### `erpgulf_gt_fields`
Override or add to the fields list for a specific product.
```php
add_filter( 'erpgulf_gt_fields', function( $fields, $post_id ) {
    // Add a custom field for a specific product type
    if ( get_post_meta( $post_id, 'is_special', true ) ) {
        $fields[] = 'meta:special_description';
    }
    return $fields;
}, 10, 2 );
```

#### `erpgulf_gt_prompt`
Customise the AI prompt for any field.
```php
add_filter( 'erpgulf_gt_prompt', function( $prompt, $field, $text, $post_id ) {
    if ( $field === 'title' ) {
        // Keep product codes and numbers intact
        $prompt = "Translate this Arabic product title to English. "
                . "Keep part numbers, codes, and years exactly as they are. "
                . "Return only the translated title.\n\n{$text}";
    }
    return $prompt;
}, 10, 4 );
```

#### `erpgulf_gt_translated_text`
Post-process any translated text before saving.
```php
add_filter( 'erpgulf_gt_translated_text', function( $translated, $original, $field, $post_id ) {
    if ( $field === 'title' ) {
        // Capitalise first letter of each word
        $translated = ucwords( strtolower( $translated ) );
    }
    return $translated;
}, 10, 4 );
```

#### `erpgulf_gt_active_provider`
Force a specific provider regardless of settings.
```php
add_filter( 'erpgulf_gt_active_provider', function( $provider ) {
    return 'openai'; // always use OpenAI
} );
```

---

### Actions

#### `erpgulf_gt_before_translate`
Fires just before translation starts for a product.
```php
add_action( 'erpgulf_gt_before_translate', function( $post_id ) {
    // Log translation start
    error_log( "Starting translation for product {$post_id}" );
} );
```

#### `erpgulf_gt_after_translate`
Fires after translation is saved successfully.
```php
add_action( 'erpgulf_gt_after_translate', function( $post_id, $translations ) {
    // Send notification
    wp_mail( 'admin@example.com', 'Product translated', "Product {$post_id} translated." );
}, 10, 2 );
```

#### `erpgulf_gt_translation_failed`
Fires when a field fails to translate.
```php
add_action( 'erpgulf_gt_translation_failed', function( $post_id, $field, $error ) {
    error_log( "Translation failed: product {$post_id}, field {$field}: {$error}" );
}, 10, 3 );
```

---

## Adding a New AI Provider

1. Create a new file e.g. `deepseek-provider.php`:

```php
<?php
/**
 * DeepSeek provider for ERPGulf AI Translate
 */
function erpgulf_gt_translate_deepseek( string $prompt, array $settings ): string|WP_Error {

    $api_key = $settings['deepseek_api_key'] ?? '';
    if ( empty( $api_key ) ) {
        return new WP_Error( 'no_key', 'DeepSeek API key not set.' );
    }

    $response = wp_remote_post( 'https://api.deepseek.com/chat/completions', [
        'timeout' => 30,
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        ],
        'body' => json_encode([
            'model'    => $settings['deepseek_model'] ?? 'deepseek-chat',
            'messages' => [ [ 'role' => 'user', 'content' => $prompt ] ],
        ]),
    ] );

    if ( is_wp_error( $response ) ) return $response;

    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    $text = $body['choices'][0]['message']['content'] ?? '';

    if ( empty( $text ) ) {
        return new WP_Error( 'empty_response', 'DeepSeek returned empty response.' );
    }

    return trim( $text );
}
```

2. Add `require_once` in the main plugin file alongside other providers.

3. Add one entry to `erpgulf_gt_ai_providers()`:

```php
'deepseek' => [
    'label'           => 'DeepSeek',
    'models'          => [ 'deepseek-chat' => 'DeepSeek Chat' ],
    'default_model'   => 'deepseek-chat',
    'key_label'       => 'DeepSeek API Key',
    'key_option'      => 'erpgulf_gt_deepseek_api_key',
    'model_option'    => 'erpgulf_gt_deepseek_model',
    'key_url'         => 'https://platform.deepseek.com',
    'key_hint'        => 'Get your API key from the DeepSeek platform.',
    'key_placeholder' => 'sk-...',
],
```

4. Go to **ERPGulf AI Translate → Settings → Active AI Provider** → select DeepSeek → Save.

No other code changes needed.

---

## WP-CLI Commands

### Find untranslated products
```bash
wp post list --post_type=product --post_status=publish --allow-root \
  --fields=ID,post_title --format=table
```

### Check translation status of a product
```bash
wp post meta get POST_ID _sku --allow-root
```

### Delete a trashed English product
```bash
wp post delete POST_ID --force --allow-root
```

### Check all trashed posts by type
```bash
wp post list --post_status=trash --post_type=any \
  --fields=ID,post_type,post_title --allow-root
```

### Permanently delete all trashed posts
```bash
wp post delete \
  $(wp post list --post_type=any --post_status=trash --format=ids --allow-root) \
  --force --allow-root
```

### Regenerate WooCommerce lookup table (fixes SKU search)
```bash
wp wc tool run regenerate_product_lookup_tables --allow-root --user=1
```

### Fix option if wrong field key was saved
```bash
wp option update erpgulf_gt_fields \
  '["title","content","excerpt","meta:manufacturer_brand","repeater:add_compactable_details"]' \
  --allow-root --format=json
```

### Check saved fields setting
```bash
wp option get erpgulf_gt_fields --allow-root
```

---

## Database Tables Used

| Table | Purpose |
|---|---|
| `wp_posts` | Product posts — Arabic and English versions |
| `wp_postmeta` | Product meta fields (price, stock, custom fields, repeaters) |
| `wp_icl_translations` | WPML language links between Arabic and English posts |
| `wp_term_taxonomy` | Product categories and offer categories |
| `wp_term_relationships` | Category assignments to products |
| `wp_wc_product_meta_lookup` | WooCommerce search lookup table (SKU search) |

---

## Troubleshooting

### English product not appearing in admin search
Run **♻️ Regenerate Lookup Table** from the settings page Tools section, or:
```bash
wp wc tool run regenerate_product_lookup_tables --allow-root --user=1
```

### Fields to Translate page shows wrong fields
Run this to reset:
```bash
wp option update erpgulf_gt_fields \
  '["title","content","excerpt","meta:manufacturer_brand"]' \
  --allow-root --format=json
```

### English version shows as existing but product was deleted
The deleted product is in Trash. Open the Arabic product — the meta box shows a **🗑️ Restore / Delete** option. Delete it permanently and retranslate.

### Compatibility data missing on English product
Go to **Settings → Tools → 🔄 Sync All Translated Products**. This copies compatibility data without needing to retranslate.

### Categories not showing on English product
Same — run **🔄 Sync All Translated Products**. For future translations, categories are handled automatically.

### Translation seems stuck or shows "Translating..."
The page may have been refreshed mid-translation. On the product edit page, simply click **Translate to English** again. The plugin updates the existing English version — it does not create duplicates.

### Branch stock not syncing
Check that `branch_stock` meta key exists on the Arabic product. The auto-sync hook fires on `updated_post_meta` and `added_post_meta` — it requires the Arabic product to be saved after the plugin is installed.

---

## Version History

### 1.0.0
- Initial release
- Google Gemini, OpenAI, and Claude providers
- Single product translation from product edit page
- Bulk translate page with live progress, pause, stop
- ACF repeater field support (compatibility, branch stock)
- Auto-sync branch stock changes Arabic → English
- Category translation and auto-creation in WPML
- Tools: Regenerate Lookup Table, Sync All Translated Products
- Trash detection with Restore / Delete buttons
- Developer hooks: filters and actions

---

## Support

**Email:** support@erpgulf.com  
**Author:** Farook K — https://medium.com/nothing-big