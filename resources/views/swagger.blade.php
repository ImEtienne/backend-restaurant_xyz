<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>API Reservations | Swagger UI</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.32.0/swagger-ui.css">
    <link rel="icon" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.32.0/favicon-32x32.png">
</head>
<body>
    <div id="swagger-ui"></div>
    <script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.32.0/swagger-ui-bundle.js"></script>
    <script>
        window.addEventListener('load', function () {
            SwaggerUIBundle({
                url: {{ Illuminate\Support\Js::from(route('docs.openapi', absolute: false)) }},
                dom_id: '#swagger-ui',
                deepLinking: true,
                displayRequestDuration: true,
                docExpansion: 'list',
                supportedSubmitMethods: ['post'],
                validatorUrl: null,
                presets: [SwaggerUIBundle.presets.apis],
                layout: 'BaseLayout',
            });
        });
    </script>
</body>
</html>