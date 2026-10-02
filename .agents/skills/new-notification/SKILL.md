# Skill: new notification type
src/bin/artisan make:notification <Name> (stub wires catalog: type string, via()=mail+broadcast, defaults, locked flag).
Add Blade markdown template; register in catalog config; preferences API picks it up automatically.
Test: assertQueued mail + assertBroadcastOn private channel + DB row persisted (offline fetch).
