# LatePoint WhatsApp Addon

**Version:** 1.0.0  
**Author:** Hari Bonda

## Overview
This addon extends LatePoint to send **One-Time Password (OTP) verification codes** via **WhatsApp** instead of traditional SMS. It leverages the official **Meta Cloud API** to ensure high delivery rates and a modern user experience.

## Features
*   **Direct Meta Integration**: Uses the official Cloud API (no insecure 3rd party wrappers).
*   **Cost Effective**: Often cheaper/more reliable than international SMS.
*   **Smart Template Handling**: Supports WhatsApp templates with variables.
*   **Button Support**: specifically designed technical fix to support "Copy Code" or "Link" buttons in templates without API errors.
*   **Core Logic Fixes**: Patches LatePoint's internal handling of `phone` vs `sms` delivery methods to ensure verification flows complete successfully.

## Prerequisites
1.  **LatePoint Plugin** installed and active.
2.  **Meta Developer App**: You must have a WhatsApp App set up in the Meta Developer Portal with a valid **Phone Number ID** and **System User Access Token**.
3.  **Main WhatsApp Integration**: This addon uses the credentials (Phone ID & Token) configured in the main LatePoint "WhatsApp by Meta" integration settings.

## Configuration Guide

### Step 1: Base Connection
Ensure your Meta API credentials are set up in the main LatePoint settings:
*   Go to `LatePoint > Settings > Integrations`.
*   Enter your **Phone Number ID** and **Access Token**.

### Step 2: Addon Specific Settings
Go to hover on wodpress `LatePoint > WhatsApp Addon Settings` to configure the OTP specifics.

| Setting | Description | Important Notes |
| :--- | :--- | :--- |
| **Template Name** | The exact technical name of your template in Meta Business Manager (e.g., `login_otp`). | The template **must** have exactly one variable `{{1}}` for the code. |
| **Template has URL Button?** | Checkbox to enable button parameter support. | **Critical Fix:** Check this box if your WhatsApp template includes a "Copy Code" or "URL" button. |

## Troubleshooting

### Error: `(#131008) Required parameter is missing`
If you receive this error when attempting to send an OTP:
1.  This means your WhatsApp Template in Meta has a **Button** (like "Copy Code").
2.  Meta requires the code to be passed specifically to the button component as well as the body.
3.  **Fix:** Go to `LatePoint > WhatsApp Addon Settings` and check the box **"Template has URL Button?"**.
4.  Save settings and try again.

### Verification Code Mismatch
If users receive the code but LatePoint says "Invalid Code" or doesn't verify:
*   This addon includes a patch to fix a database mismatch where LatePoint records the delivery method as `phone` but expects `sms`.
*   Ensure the addon is active; it automatically updates the delivery method to `sms` in the database so the verification form works correctly.
