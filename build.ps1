# Build Script for DotySync for WooCommerce
# Creates a production-ready zip file for WordPress.org submission.

$pluginSlug = "dotysync-for-woocommerce"
$zipName = "$pluginSlug.zip"

write-host "Starting Build for $pluginSlug..."

# 1. Cleanup previous build artifacts
if (Test-Path $pluginSlug) { 
    write-host "Removing existing temp directory..."
    Remove-Item $pluginSlug -Recurse -Force 
}
if (Test-Path $zipName) { 
    write-host "Removing existing zip file..."
    Remove-Item $zipName -Force 
}

# 2. Create Temp Directory
write-host "Creating temp directory..."
New-Item -ItemType Directory -Path $pluginSlug | Out-Null

# 3. Define Exclusions
$exclude = @(
    ".git", 
    ".github", 
    ".distignore", 
    ".vscode", 
    "build.sh", 
    "build.ps1", 
    "node_modules", 
    "*.zip", 
    "*.md", # Exclude all markdown files (we will explicitly include readme.txt if needed, but standard is just .txt or .md)
    # Wait, WordPress wants readme.txt. standard markdown files like 'dotypos...md' are clutter.
    # Let's strictly exclude the specific file reported if we want, or better: exclude *.md but keep standard ones if any?
    # Actually, the user has a specific long markdown file. Let's exclude *.md and .json and just keep readme.txt implicitly if it's .txt.
    "*.json",
    "$pluginSlug" # Exclude the temp dir itself
)

# 4. Copy Files
write-host "Copying files..."
$items = Get-ChildItem -Path . -Exclude $exclude
foreach ($item in $items) {
    Copy-Item -Path $item.FullName -Destination "$pluginSlug\$($item.Name)" -Recurse -Force
}

# 5. Deep Cleanup (Ensure nested excluded files are removed)
write-host "Performing deep cleanup of forbidden files..."
$forbidden = @(".git", ".github", ".distignore", ".vscode", "node_modules", ".DS_Store")
foreach ($bad in $forbidden) {
    Get-ChildItem -Path $pluginSlug -Recurse -Force -ErrorAction SilentlyContinue | Where-Object { $_.Name -eq $bad } | Remove-Item -Recurse -Force
}

# 6. Create Zip
write-host "Zipping files..."
Compress-Archive -Path $pluginSlug -DestinationPath $zipName

# 7. Cleanup Temp
write-host "Cleaning up temp directory..."
Remove-Item $pluginSlug -Recurse -Force

write-host "----------------------------------------"
write-host "Build Complete: $zipName"
write-host "----------------------------------------"
