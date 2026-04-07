# Huawei Enterprise Application Draft for PlanRun

Source template:
`/var/www/planrun/Application+Material+(for+Enterprise+Developers)_V3.1.pdf`

Draft PDF created:
`/var/www/planrun/Application+Material+(for+Enterprise+Developers)_V3.1.planrun-draft.pdf`

## Already prepared for PlanRun

### Page 1. Data Usage

`Permissions`

Read activity summary data; read workout/activity records; read historical activity data (month scope).

`Data usage scenarios and requirements`

PlanRun allows a user to connect Huawei Health from Settings > Integrations. After user authorization, the system imports completed workouts into the training calendar and workout history, shows distance, duration, calories and activity type, and compares planned vs completed training. Only user-authorized workout data is synchronized.

`Data usage purpose`

Workout synchronization, workout history display, training analytics, and coaching insights inside PlanRun. The data is used only to provide the user with fitness tracking and training management features.

`Development completion time`

2026.04.15

### Recommended values for Page 5. Project Details

`Application (app) introduction`

PlanRun is a training planning and workout tracking platform for runners and endurance athletes. It provides a training calendar, workout history, analytics, and coaching features. Huawei Health integration is used to import user-authorized completed workouts into PlanRun so users can sync their activity history and compare planned versus completed training.

`Application industry`

Sports and fitness / digital health / training management

`Project leader for Huawei-side connection`

Use the real project owner name, role, and email.

`Cooperation details`

Huawei Health Kit integration for workout synchronization through REST API.

`Do you have any purchasing needs?`

No purchasing plan at this stage.

### Recommended values for Page 6. Technical Self-test

`Open technical integration`

REST API

`Whether the data permissions applied for can meet the needs`

Y

`Whether the data usage scenarios and requirements of the application involve medical treatment`

N

`Expected authorized user scale and interface request TPS`

Initial rollout: up to 10,000 authorized users, expected TPS below 10.

## Still needs manual input from the company

### Page 2

- Upload screenshots of the app simulation.
- Recommended screenshots:
  - Huawei Health connection entry in Settings > Integrations
  - OAuth/connection flow screen
  - Imported workout visible in calendar or workout history

### Page 3. Company Description

- Has the company legally existed for more than 1 year: Yes or No
- Remark
- Registered country/region
- Registered paid-up capital
- Brief text introduction of the company
- Company name
- Business type
- Legal representative
- Business scope
- Business license, if you want to attach it

### Page 4

- Upload a company business scope image only if you do not want to rely on the text introduction from Page 3.

### Page 5

- Project leader for Huawei-side connection: real name, email, phone or Telegram if needed

### Page 6

- Countries this app serves
- Commercial release or Demo
- Select all application types to access Health Service Kit

## Recommended choices for the remaining uncertain technical fields

`Countries this app serves`

Use only the real markets you can support operationally.

`Commercial release or Demo`

If users already access the live product, use `Commercial Release`.
If this is still a review-stage integration without production launch, use `Demo`.

`Select all the application types you want to access the Health Service Kit`

Safest first submission:
Android mobile applications; iOS mobile applications

Only add web/H5 if you really need Huawei to review that scenario too.
