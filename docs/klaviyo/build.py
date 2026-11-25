#!/usr/bin/env python3
"""Builds the Klaviyo copies of the site's emails (docs/klaviyo/*.html) in the "ZENOO Email Template"
style, the same as templates/emails/. Each file is a whole email: in Klaviyo, create a template with the
HTML editor and paste the file. All text comes from the event (copy_* already translated by the site).

    python3 docs/klaviyo/build.py
"""
from pathlib import Path

OUT = Path(__file__).parent
FONT = "font-family:Inter,Arial,Helvetica,sans-serif;"


def eyebrow(text):
    return f'<p style="margin:0 0 15px;{FONT}font-size:14px;line-height:20px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:#445942;">{text}</p>'


def h1(text):
    return f'<h1 style="margin:0 0 15px;{FONT}font-size:28px;line-height:32px;font-weight:800;letter-spacing:-.02em;text-transform:uppercase;color:#445942;">{text}</h1>'


def h2(text):
    return f'<h2 style="margin:0 0 15px;{FONT}font-size:20px;line-height:26px;font-weight:800;text-transform:uppercase;color:#445942;">{text}</h2>'


def p(text, last=False):
    return f'<p style="margin:0 0 {0 if last else 15}px;{FONT}font-size:16px;line-height:26px;letter-spacing:.6px;color:#5a6b59;">{text}</p>'


def small(text):
    return f'<p style="margin:0 0 10px;{FONT}font-size:13px;line-height:20px;color:#5f6e5e;word-break:break-word;">{text}</p>'


def button(url, label):
    return (f'<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:10px 0 20px;"><tr><td bgcolor="#f7a223" style="background:#f7a223;border-radius:9999px;">'
            f'<a href="{url}" style="display:inline-block;padding:16px 28px;{FONT}font-size:16px;line-height:20px;font-weight:700;letter-spacing:.03em;text-transform:uppercase;color:#263326;text-decoration:none;border-radius:9999px;">{label}</a></td></tr></table>')


def outline(url, label):
    return (f'<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:5px 0 0;"><tr><td bgcolor="#ffffff" style="background:#ffffff;border:2px solid #445942;border-radius:9999px;">'
            f'<a href="{url}" style="display:inline-block;padding:10px 20px;{FONT}font-size:14px;line-height:20px;font-weight:700;letter-spacing:.03em;text-transform:uppercase;color:#445942;text-decoration:none;">{label}</a></td></tr></table>')


def step(n, text):
    return (f'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 15px;border:1px solid #dde5dc;border-radius:10px;border-collapse:separate;"><tr>'
            f'<td width="36" valign="top" style="padding:20px 0 20px 20px;"><div style="width:36px;height:36px;line-height:36px;border-radius:9999px;background:#dde5dc;text-align:center;{FONT}font-size:15px;font-weight:800;color:#445942;">{n}</div></td>'
            f'<td valign="top" style="padding:20px 20px 20px 15px;{FONT}font-size:16px;line-height:26px;letter-spacing:.6px;font-weight:700;color:#445942;">{text}</td></tr></table>')


def stat(big, caption):
    return (f'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;border-collapse:separate;"><tr><td bgcolor="#445942" align="center" style="background:#445942;border-radius:10px;padding:30px 20px;">'
            f'<p style="margin:0;{FONT}font-size:56px;line-height:60px;font-weight:800;color:#ffffff;">{big}</p>'
            f'<p style="margin:6px 0 0;{FONT}font-size:16px;line-height:22px;font-weight:800;text-transform:uppercase;color:#ffffff;">{caption}</p></td></tr></table>')


CARD_OPEN = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;border:1px solid #dde5dc;border-radius:10px;border-collapse:separate;"><tr><td style="padding:20px;">'
CARD_CLOSE = '</td></tr></table>'


def email(title, note, content, band='', after='', unsubscribe=''):
    band_rows = ''
    if band:
        band_rows = (f'<tr><td class="zx-pad" bgcolor="#f8f7f2" style="background:#f8f7f2;padding:30px 20px;">{band}</td></tr>\n'
                     f'<tr><td class="zx-pad" style="padding:30px 20px 0;">{after}</td></tr>\n')
    return f'''<!-- ZENOO site email: {note}
     Whole email in the "ZENOO Email Template" style. Built by docs/klaviyo/build.py: edit that, not this file. -->
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<meta name="color-scheme" content="light">
<title>{title}</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700;800&amp;display=swap" rel="stylesheet">
<style>body{{margin:0;padding:0;}} a{{color:#445942;}} @media (max-width:620px){{.zx-pad{{padding-left:16px!important;padding-right:16px!important;}}}}</style>
</head>
<body style="margin:0;padding:0;background:#e9ebe6;{FONT}color:#5a6b59;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#e9ebe6" style="background:#e9ebe6;">
<tr><td align="center" style="padding:24px 0;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" bgcolor="#ffffff" style="width:100%;max-width:600px;background:#ffffff;">
<tr><td align="center" bgcolor="#445942" style="background:#445942;height:84px;padding:0 20px;"><a href="{{{{ event.site_url }}}}" style="display:inline-block;"><img src="{{{{ event.site_url }}}}/images/logo-white.png" width="140" alt="ZENOO" style="display:block;border:0;width:140px;height:auto;"></a></td></tr>
<tr><td class="zx-pad" style="padding:40px 20px 10px;">
{content}
</td></tr>
{band_rows}<tr><td class="zx-pad" style="padding:20px 20px 40px;">
{p('{{ event.copy_regards }}', True)}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:10px;"><tr><td style="border-top:1px solid #dde5dc;padding-top:10px;">
<p style="margin:0;{FONT}font-size:20px;line-height:26px;font-weight:800;color:#445942;">Agota</p>
<p style="margin:2px 0 0;{FONT}font-size:14px;line-height:20px;color:#5f6e5e;">{{{{ event.copy_signer_role }}}}</p>
</td></tr></table>
</td></tr>
<tr><td class="zx-pad" bgcolor="#445942" style="background:#445942;padding:40px 20px 20px;{FONT}color:#ffffff;">
<a href="{{{{ event.site_url }}}}" style="display:inline-block;"><img src="{{{{ event.site_url }}}}/images/logo-white.png" width="110" alt="ZENOO" style="display:block;border:0;width:110px;height:auto;"></a>
<p style="margin:20px 0;{FONT}font-size:12px;line-height:22px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;"><a href="{{{{ event.site_url }}}}/dashboard" style="color:#ffffff;text-decoration:none;white-space:nowrap;">{{{{ event.copy_nav_dog }}}}</a>&nbsp;&nbsp;&middot;&nbsp;&nbsp;<a href="{{{{ event.site_url }}}}/chat" style="color:#ffffff;text-decoration:none;white-space:nowrap;">{{{{ event.copy_nav_coach }}}}</a>&nbsp;&nbsp;&middot;&nbsp;&nbsp;<a href="{{{{ event.site_url }}}}/courses" style="color:#ffffff;text-decoration:none;white-space:nowrap;">{{{{ event.copy_nav_courses }}}}</a>&nbsp;&nbsp;&middot;&nbsp;&nbsp;<a href="{{{{ event.site_url }}}}/account" style="color:#ffffff;text-decoration:none;white-space:nowrap;">{{{{ event.copy_nav_account }}}}</a></p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td style="border-top:1px solid #6b7d69;padding-top:20px;{FONT}font-size:12px;line-height:18px;color:#ffffff;">
<p style="margin:0 0 6px;">&copy; ZENOO &middot; <a href="mailto:support@myzenoo.com" style="color:#ffffff;">support@myzenoo.com</a></p>
<p style="margin:0;">{{{{ event.copy_footer }}}} <a href="{{{{ event.site_url }}}}" style="color:#ffffff;">{{{{ event.copy_site }}}}</a>.</p>
{unsubscribe}
</td></tr></table>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
'''


def off(url):
    return f'<p style="margin:6px 0 0;"><a href="{url}" style="color:#ffffff;text-decoration:underline;">{{{{ event.copy_off }}}}</a></p>'


E = lambda k: '{{ event.%s }}' % k  # noqa: E731

ROW = f'{FONT}font-size:15px;line-height:22px;'


def row(label, value, extra=''):
    return (f'<tr><td style="{ROW}padding:0 0 10px;color:#5f6e5e;">{label}</td>'
            f'<td align="right" style="{ROW}padding:0 0 10px;font-weight:700;color:#445942;{extra}">{value}</td></tr>')


FILES = {
    'quiz': email(E('subject'), '"quiz" flow (event "ZENOO Quiz Confirmation").', '\n'.join([
        stat(E('bowl_score') + '/100', E('dog_name') + ' &middot; ' + E('copy_bowl_score')),
        h1(E('copy_confirm_title')), p(E('copy_greeting')), p(E('copy_intro')),
        button(E('confirm_link'), E('copy_button')),
        small(E('copy_note')), small(E('copy_link_hint') + ' ' + E('confirm_link')),
    ])),
    'welcome': email(E('subject'), '"welcome" flow (event "ZENOO Welcome").', '\n'.join([
        eyebrow(E('copy_account')), h1(E('copy_heading')), p(E('copy_intro')), p(E('copy_text')),
        button(E('quiz_url'), E('copy_button')), small(E('copy_note')),
    ])),
    'reset': email(E('subject'), '"reset" flow (event "ZENOO Password Reset").', '\n'.join([
        eyebrow(E('copy_account')), h1(E('copy_heading')), p(E('copy_intro')),
        button(E('reset_link'), E('copy_button')),
        small(E('copy_note')), small(E('copy_link_hint') + ' ' + E('reset_link')),
    ])),
    'login': email(E('subject'), '"login" flow (event "ZENOO Login Link").', '\n'.join([
        eyebrow(E('copy_account')), h1(E('copy_heading')), p(E('copy_intro')),
        button(E('login_link'), E('copy_button')),
        small(E('copy_note')), small(E('copy_link_hint') + ' ' + E('login_link')),
    ])),
    'changed': email(E('subject'), '"changed" flow (event "ZENOO Password Changed").', '\n'.join([
        eyebrow(E('copy_account')), h1(E('copy_heading')), p(E('copy_intro')), p(E('copy_text')),
        button(E('forgot_password_url'), E('copy_button')),
    ])),
    'order': email(E('subject'), '"order" flow (event "ZENOO Order Confirmation").', '\n'.join([
        eyebrow(E('copy_order_label') + ' #' + E('order_id')), h1(E('copy_heading')), p(E('copy_intro')),
        '{% if event.test_order %}' + f'<p style="margin:0 0 20px;padding:12px 15px;background:#fff0d6;border-radius:10px;{FONT}font-size:14px;line-height:22px;color:#935a00;"><strong>{E("copy_test_label")}</strong> {E("copy_test_text")}</p>' + '{% endif %}',
        CARD_OPEN + '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">',
        row(E('copy_order_label'), '#' + E('order_id')),
        row(E('copy_product_label'), E('product_name')),
        row(E('copy_date_label'), E('copy_date')),
        '{% if event.discount %}' + row(E('copy_discount_label'), '&minus;&euro;{{ event.discount|floatformat:2 }}') + '{% endif %}',
        f'<tr><td style="{ROW}padding:10px 0 0;border-top:1px solid #dde5dc;color:#5f6e5e;">{E("copy_total_label")}</td>'
        f'<td align="right" style="{FONT}padding:10px 0 0;border-top:1px solid #dde5dc;font-size:28px;line-height:34px;font-weight:800;color:#445942;">&euro;{{{{ event.amount|floatformat:2 }}}}</td></tr>',
        '</table>' + CARD_CLOSE,
        button(E('order_url'), E('copy_button')), small(E('copy_note')),
    ])),
    'followup': email(E('subject'), '"followup" flow (event "ZENOO Coach Check-in"; the question is in the pet parent\'s language).', '\n'.join([
        eyebrow(E('dog_name') + ' &middot; ' + E('copy_coach')), h1(E('copy_heading')), p(E('copy_greeting')), p(E('copy_intro'), True),
    ]), band=f'<p style="margin:0;{FONT}font-size:18px;line-height:26px;font-weight:700;color:#445942;">{E("question")}</p>',
        after='\n'.join([button(E('chat_url'), E('copy_button')), p(E('copy_note'), True)]),
        unsubscribe=off(E('off_url'))),
    'plan': email(E('subject'), '"plan" flow (event "ZENOO Plan"; fixes and plan lines are in the pet parent\'s language).', '\n'.join([
        stat(E('bowl_score') + '/100', E('dog_name') + ' &middot; ' + E('copy_bowl_score')),
        p(E('copy_greeting')), p(E('copy_intro')),
        '{% if event.fix_1 %}<div style="height:10px;line-height:10px;">&nbsp;</div>' + h2(E('copy_fixes_title')) + step(1, E('fix_1'))
        + '{% if event.fix_2 %}' + step(2, E('fix_2')) + '{% endif %}{% if event.fix_3 %}' + step(3, E('fix_3')) + '{% endif %}{% endif %}',
    ]), band='\n'.join([
        h2(E('copy_plan_title')),
        '{% if event.copy_kcal %}' + f'<p style="margin:0 0 10px;{FONT}font-size:15px;line-height:22px;font-weight:700;letter-spacing:.6px;color:#445942;">{E("copy_kcal")}</p>' + '{% endif %}',
        '{% if event.plan_food %}' + p(E('plan_food')) + '{% endif %}',
        '{% if event.plan_meals %}' + p(E('plan_meals'), True) + '{% endif %}',
    ]), after='\n'.join([
        '{% if event.copy_zenoo_text %}' + CARD_OPEN
        + f'<p style="margin:0 0 6px;{FONT}font-size:20px;line-height:26px;font-weight:800;color:#445942;">{E("copy_zenoo_title")}</p>'
        + f'<p style="margin:0 0 10px;{FONT}font-size:15px;line-height:22px;color:#5a6b59;">{E("copy_zenoo_text")}</p>'
        + outline(E('plan_url'), E('copy_zenoo_link')) + CARD_CLOSE + '{% endif %}',
        button(E('dashboard_url'), E('copy_button')), p(E('copy_note'), True),
    ])),
    'series': email(E('subject'), '"series" flow (event "ZENOO Plan Series", day 3, 7 and 14 to opted-in pet parents; event.day says which). Mark this flow as marketing (unsubscribe link in the footer).', '\n'.join([
        eyebrow(E('dog_name')), h1(E('copy_heading')), p(E('copy_greeting')), p(E('copy_text')),
        button(E('button_url'), E('copy_button')),
    ]), unsubscribe=off(E('off_url'))),
}

for name, html in FILES.items():
    (OUT / f'{name}.html').write_text(html)
print('Wrote', ', '.join(f'{n}.html' for n in FILES))
