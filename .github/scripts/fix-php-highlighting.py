#!/usr/bin/env python3
"""
Fix PHP syntax highlighting by adding <?php to code blocks that don't have it.
"""
import re
import sys
from pathlib import Path

def fix_php_code_blocks(content):
    """Add <?php tag to PHP code blocks that are missing it."""
    def replace_php_block(match):
        code = match.group(1)
        # Check if the code already starts with <?php
        if code.strip().startswith('<?php'):
            return match.group(0)  # Return unchanged
        # Add <?php at the beginning
        return f"```php\n<?php\n{code}```"

    # Pattern to match ```php code blocks
    pattern = r'```php\n(.*?)```'
    return re.sub(pattern, replace_php_block, content, flags=re.DOTALL)

def process_file(filepath):
    """Process a single markdown file."""
    try:
        with open(filepath, 'r', encoding='utf-8') as f:
            content = f.read()

        fixed_content = fix_php_code_blocks(content)

        if content != fixed_content:
            with open(filepath, 'w', encoding='utf-8') as f:
                f.write(fixed_content)
            print(f"Fixed: {filepath}")
            return True
        return False
    except Exception as e:
        print(f"Error processing {filepath}: {e}", file=sys.stderr)
        return False

def main():
    docs_dir = Path('docs')
    if not docs_dir.exists():
        print(f"Error: docs directory not found", file=sys.stderr)
        sys.exit(1)

    # Process all markdown files
    files_fixed = 0
    for md_file in docs_dir.rglob('*.md'):
        if process_file(md_file):
            files_fixed += 1

    print(f"\nProcessed {files_fixed} file(s) with PHP code blocks")

if __name__ == '__main__':
    main()
