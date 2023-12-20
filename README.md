# multi-tenancy


`composer require controleonline/multi-tenancy:dev-master`


Add Service import:
config\services.yaml

```yaml
imports:
    - { resource: "../vendor/controleonline/multi-tenancy/financial/services/multi-tenancy.yaml" }    
```

Add a header on CORS:
config\packages\nelmio_cors.yaml

```yaml
nelmio_cors:
    defaults:
        origin_regex: true
        allow_origin: ['%env(CORS_ALLOW_ORIGIN)%']
        allow_methods: ['GET', 'OPTIONS', 'POST', 'PUT', 'PATCH', 'DELETE']
        allow_headers: ['Content-Type', 'Authorization', 'API-TOKEN','app-domain']
        expose_headers: ['Link']
        max_age: 3600
    paths:
        '^/': ~
```
