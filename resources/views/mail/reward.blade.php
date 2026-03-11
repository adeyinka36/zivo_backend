@component('mail::message')
# 🎁 You have a reward from ZI-VO

Hi {{ $notifiableName }},

Great news – you paid attention and have unlocked earned reward on ZI-VO.

@component('mail::panel')
**Your offer**

{{ $offer }}

@if($hasCode)
---

**Your code**

`{{ $code }}`

_Show this code when you claim your reward._
@endif
@endcomponent

@if($fromDate && $toDate)
**Valid**

• {{ $fromDate }} — {{ $toDate }}

@endif

**Where to claim**

• {{ $claimLocation }}

When you visit, simply show this email to the business so they can validate and apply your offer.  
This offer is personal to you and linked to your ZI-VO account. Please keep this email safe until you have redeemed it.

Thank you for paying attention!

Best regards,  
ZI-VO

@endcomponent

