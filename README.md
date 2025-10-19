



apt install php8.1-apcu 



# How it works
- Read all files and register routes
- match an incoming http path to known routes
- apply middleware

# Optmizations
All registered routes are cached with apcu, so first call read all file, next are cached (takes 33ms on my machine for 4 routes in 2 files)
Routes are stored in key value format where the key is the route until the first parameter. For /api/users/{id} registered route, when a http request is made /api/users/49, it tries keys in this order /api/users/49, then /api/users/, then /api/users then /api/ then /api, where /api/users/ is matched.

