#!/bin/bash

# Setup script for Pagent git hooks

echo "Setting up Pagent git hooks..."

# Get the directory where this script is located
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
GIT_DIR="$(git rev-parse --git-dir)"

# Configure git to use the .githooks directory
git config core.hooksPath "$SCRIPT_DIR"

# Make all hook scripts executable
chmod +x "$SCRIPT_DIR"/*

echo "Git hooks configured successfully!"
echo "Hooks location: $SCRIPT_DIR"
echo ""
echo "Available hooks:"
ls -la "$SCRIPT_DIR" | grep -v setup.sh | grep -v '^\.'
echo ""
echo "To bypass hooks for a single commit, use: git commit --no-verify"
