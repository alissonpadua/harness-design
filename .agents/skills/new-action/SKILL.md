# Skill: new write endpoint
FormRequest (rules+authorize) → *Data DTO → Action::handle(Data): Model|void → Event → Resource.
Placement: app/Actions/<Area>/, app/Data/, app/Http/Requests/, app/Http/Controllers/<Area>/, app/Resources/.
Route in routes/api.php under auth+org middleware (or admin-v1.php). Abilities + throttle per M6.
Arch-test legality: no DB:: in controllers, no mailer/HTTP in Actions (dispatch instead).
