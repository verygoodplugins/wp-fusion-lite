# Integration keyword exclusion list

`filter_changelog.py` reads this file and drops any Pro changelog bullet
that contains one of these keywords (case-insensitive, with non-alphanumeric
word boundaries). Keep the bare format — a leading `- ` per keyword — so the
parser picks them up.

When Pro ships a new integration, add its name here. The filter is
intentionally conservative: if a bullet mentions a paid-plugin integration
AND a CRM, it still gets dropped (the CRM aspect will usually show up in
another bullet you can keep).

## E-commerce

- WooCommerce
- Woo
- WC
- EDD
- Easy Digital Downloads
- Subscriptions for WooCommerce
- WooFunnels
- CartFlows
- SureCart
- GiveWP
- Give
- WP Crowdfunding
- Tickera
- Event Tickets
- Tribe Tickets
- WP PayForm
- Stripe Payments
- SimplePay
- Simple Pay

## LMS / courses

- LearnDash
- LifterLMS
- LearnPress
- Tutor LMS
- Tutor
- Sensei
- Thrive Apprentice
- MasterStudy
- WPLMS
- CoursePress
- Uncanny Groups
- LD Group Registration

## Memberships

- AccessAlly
- MemberPress
- Paid Memberships Pro
- PMPro
- Restrict Content Pro
- RCP
- SureMembers
- SureDash
- WishList Member
- MemberDash
- MemberMouse
- Memberium
- Memberoni
- s2Member
- Ultimate Member
- Simple Membership
- WP-Members
- ProfilePress
- Profile Builder
- UsersWP
- WP User Manager
- Members plugin

## Forms

- Gravity Forms
- GravityView
- Gravity
- Fluent Forms
- Forminator
- WPForms
- Ninja Forms
- Formidable Forms
- Caldera Forms
- Piotnet Forms
- Presto Player Forms
- Ws Form
- WS Forms
- WSForm
- Toolset Forms
- SureForms
- Contact Form 7

## Page builders / themes

- Elementor
- Elementor Forms
- Elementor Pro
- Divi
- Beaver Builder
- Beaver Themer
- Bricks
- Breakdance
- Oxygen
- WPBakery
- Thrive Architect
- Thrive Automator

## Communities / BuddyPress-family

- BuddyPress
- BuddyBoss
- bbPress
- PeepSo
- WPForo

## Booking / appointments / events / tickets

- Amelia
- Ameliabooking
- BookingPress
- Woo Bookings
- WooCommerce Bookings
- Salon Booking
- Fluent Booking
- LatePoint
- Simply Schedule Appointments
- Modern Events Calendar
- EventON
- Event Espresso
- WP Event Manager
- WP Booking System
- FooEvents
- FooPlugins

## Gamification

- BadgeOS
- GamiPress
- myCred

## Affiliate / referrals

- AffiliateWP
- Solid Affiliate
- SliceWP
- WP Affiliate Manager
- Thirsty Affiliates
- Refer a Friend
- YITH Vendors

## Other integrations / tooling

- ACF
- Advanced Custom Fields
- JetEngine
- Toolset
- Pods
- Metabox
- WP All Import
- CPT UI
- Popup Maker
- If-So
- Holler Box
- Clickwhale
- FacetWP
- Document Library Pro
- Pretty Links
- Presto Player
- Fluent Community
- FluentCommunity
- Fluent Cart
- FluentCart
- Studio Cart
- GS Product Configurator
- Tribe Events
- MEC
- WP Job Manager
- WP Ultimo
- Uncanny Automator
- Object Sync for Salesforce
- WPML
- TranslatePress
- Weglot
- GTranslate
- BuddyBoss App
- Blockli
- Users Insights
- Remote Users Sync
- Share Logins Pro
- miniOrange JWT Login
- Clean Login
- Login with AJAX
- Download Monitor
- Download Manager
- E-Signature
- GeoDirectory
- Advanced Ads
- ClickWhale
- Content Control

## Notes

- Do NOT add CRM names here (HubSpot, Klaviyo, Drip, etc.). CRMs ship in
  Lite — their bullets should stay.
- Aliases matter: Pro's changelog sometimes uses full names, sometimes
  abbreviations. When adding a new integration, include both.
- The matcher lowercases everything and requires non-alnum word boundaries,
  so "Woo" won't match "Woocommerce" — add both when needed.
