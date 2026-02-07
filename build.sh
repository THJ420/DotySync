#!/bin/bash

# Build Script for DotySync for WooCommerce
# Usage: ./build.sh

PLUGIN_SLUG="dotysync-for-woocommerce"
ZIP_NAME="${PLUGIN_SLUG}.zip"

# Clean up previous build
rm -rf "$PLUGIN_SLUG" "$ZIP_NAME"

# Create directory
mkdir "$PLUGIN_SLUG"

# Copy files
echo "Copying files..."
cp -r admin assets includes vendor "$PLUGIN_SLUG/" 2>/dev/null
cp dotysync-for-woocommerce.php readme.txt "$PLUGIN_SLUG/"

# Remove excluded files/dirs just in case copy was too broad (though explicit list above is safer)
# But if 'includes' has dev stuff, clean it here.
# Assuming 'vendor' is required for prod if it exists.

# Zip it
echo "Zipping..."
zip -r "$ZIP_NAME" "$PLUGIN_SLUG" -x "*.git*" "*.DS_Store*" "*/node_modules/*" "*/src/*" "*/.vscode/*" "*/webpack.config.js"

# Clean up directory
rm -rf "$PLUGIN_SLUG"

echo "Done! Created $ZIP_NAME"
