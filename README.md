[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/controleonline/api-platform-multi-tenancy/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/controleonline/api-platform-multi-tenancy/?branch=master)

# multi-tenancy


`composer require controleonline/multi-tenancy:dev-master`


Add Service import:
config\services.yaml

```yaml
imports:
    - { resource: "../vendor/controleonline/multi-tenancy/config/config.yaml" }    
```

Add 'app-domain' a header on CORS:
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
