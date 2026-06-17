=== Post Summary Feature ===

This plugin adds a "Summarise this post" button below the content of single blog posts.

Notes:
- The button appears only on single blog posts (`post` post type).
- The AJAX request is handled on the server side.
- The OpenAI API key is expected to be defined in wp-config.php as `OPENAI_API_KEY`.
- If no real API key is available, the plugin returns a realistic mocked summary response for demonstration purposes.
