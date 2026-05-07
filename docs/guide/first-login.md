# First Login

## Setup screen

When you visit `/admin` for the first time (or after a [user reset](./recovery)), CometCMS shows the **setup screen** instead of the login page.

Enter a username and a password (minimum 8 characters) to create the initial admin account. This is the only account that can access the panel until more users are added.

![CometCMS setup screen for creating the first admin account](../screenshots/first-login.png)

## Admin URL

The admin panel is always available at:

```
https://yourdomain.com/admin
```

## Logging in

Enter your username and password. Sessions are server-side (PHP sessions stored in `cms/storage/sessions/`).

## Your profile

Click your **name or avatar in the bottom-left corner of the sidebar** to open your profile page where you can:

- Upload or remove your profile picture
- Change your display name and email address
- Set a new password (requires entering your current password first)
