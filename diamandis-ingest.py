#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Peter H. Diamandis Newsletter Kompendium - CLI Ingestion Tool
Erlaubt das automatische Scannen des Substack-Archivs (metatrends.substack.com)
mit Pagination (bis zu 100 Artikel), Überarbeitung von Artikeln < 500 Wörtern,
und Batch-Verarbeitung von bis zu 6 Artikeln pro Klick/Durchlauf.
"""

import sys
import os
import re
import json
import time
import urllib.request
import urllib.error
import xml.etree.ElementTree as ET
from datetime import datetime

if hasattr(sys.stdout, 'reconfigure'):
    try:
        sys.stdout.reconfigure(encoding='utf-8', errors='replace')
        sys.stderr.reconfigure(encoding='utf-8', errors='replace')
    except Exception:
        pass

# =========================================================================
# KONFIGURATION & AUSSCHLUSSLISTE (IGNORED ARTICLES)
# Hier können Substack-Links, Slugs oder IDs eingetragen werden, die beim
# Synchronisieren / Scannen ignoriert und aus der Anzeige ausgeschlossen werden sollen.
# =========================================================================
IGNORED_SUBSTACK_POSTS = [
    "https://metatrends.substack.com/p/the-evidence-around-uaps-is-getting",
    # Zukünftige ignorierte Links hier eintragen...
]

def is_post_ignored(item_or_url):
    if not item_or_url:
        return False
    test_str = ""
    if isinstance(item_or_url, str):
        test_str = item_or_url.lower().strip()
    elif isinstance(item_or_url, dict):
        test_str = " ".join([
            item_or_url.get("sourceUrl", ""),
            item_or_url.get("link", ""),
            item_or_url.get("id", ""),
            item_or_url.get("slug", ""),
            item_or_url.get("title", "")
        ]).lower().strip()
    for ignored in IGNORED_SUBSTACK_POSTS:
        clean = ignored.lower().strip()
        if not clean:
            continue
        if clean in test_str:
            return True
        if "/p/" in clean:
            slug = clean.split("/p/")[1].split("?")[0].split("#")[0].strip()
            if slug and slug in test_str:
                return True
    return False

DATA_FILE = os.path.join(os.path.dirname(__file__), "diamandis-data.js")

def load_data():
    if not os.path.exists(DATA_FILE):
        return []
    with open(DATA_FILE, "r", encoding="utf-8") as f:
        content = f.read()
    match = re.search(r"const\s+INITIAL_NEWSLETTERS\s*=\s*(\[.*\]);", content, re.DOTALL)
    if match:
        try:
            items = json.loads(match.group(1))
            valid_items = [it for it in items if not is_post_ignored(it)]
            return valid_items
        except Exception as e:
            print(f"Fehler beim Parsen von diamandis-data.js: {e}")
    return []

def get_required_word_count(item=None):
    if item and isinstance(item, dict) and item.get("id"):
        return 400
    return 500

def save_data(newsletters):
    newsletters = [it for it in newsletters if not is_post_ignored(it)]
    newsletters.sort(key=lambda x: x.get("date", ""), reverse=True)
    content = "/**\n * Peter H. Diamandis Newsletter Kompendium - Datenspeicher\n * Dieses Array enthaelt alle erfassten Artikel/Newsletter auf Deutsch.\n */\nconst INITIAL_NEWSLETTERS = " + \
              json.dumps(newsletters, indent=2, ensure_ascii=False) + ";\n"
    with open(DATA_FILE, "w", encoding="utf-8") as f:
        f.write(content)
    print(f"\n[OK] {len(newsletters)} Artikel erfolgreich in diamandis-data.js gespeichert.")

def count_words(html_text):
    if not html_text:
        return 0
    clean = re.sub(r"<[^>]+>", " ", html_text)
    return len(clean.split())

class GeminiKeyManager:
    def __init__(self, keys):
        if isinstance(keys, str):
            self.keys = [k.strip() for k in keys.split(",") if k.strip()]
        elif isinstance(keys, (list, tuple)):
            self.keys = [k.strip() for k in keys if k and str(k).strip()]
        else:
            self.keys = []
        self.active_index = 0

    @property
    def has_keys(self):
        return len(self.keys) > 0

    @property
    def total(self):
        return len(self.keys)

    def get_active_key(self):
        if not self.keys:
            return None
        return self.keys[self.active_index]

    def get_active_tag(self):
        if not self.keys:
            return "[Kein Key]"
        return f"[Key #{self.active_index + 1}]"

    def rotate_key(self):
        if len(self.keys) <= 1:
            return self.get_active_key()
        old_idx = self.active_index
        self.active_index = (self.active_index + 1) % len(self.keys)
        new_idx = self.active_index
        print(f"\n  [!] Quota für Key #{old_idx + 1} erschöpft. Wechsle sofort zu Key #{new_idx + 1}...")
        return self.keys[new_idx]

def is_model_not_found_error(err):
    msg = str(err).lower()
    return any(k in msg for k in [
        "not found", "not supported", "404", "modelservice.listmodels"
    ])

def is_json_syntax_error(err):
    msg = str(err).lower()
    return any(k in msg for k in [
        "json", "syntaxerror", "unexpected token", "expecting", "delimiter", "char", "position"
    ])

def is_quota_error(err):
    msg = str(err).lower()
    return any(k in msg for k in [
        "resource_exhausted", "resource exhausted", "quota exceeded",
        "rate limit", "too many requests", "check your plan and billing",
        "quota"
    ]) or ("429" in msg and "high demand" not in msg and "temporarily" not in msg)

def is_overload_error(err):
    msg = str(err).lower()
    return any(k in msg for k in [
        "high demand", "spikes in demand", "overloaded",
        "temporarily overloaded", "try again later", "503", "500"
    ])

def safe_parse_gemini_json(raw_text):
    if not raw_text:
        raise ValueError("Keine Antwort von Gemini erhalten.")
    clean = str(raw_text).strip()

    # 1. Remove markdown code fences
    if clean.startswith("```"):
        clean = re.sub(r"^```(?:json)?\s*", "", clean, flags=re.IGNORECASE)
        clean = re.sub(r"\s*```$", "", clean, flags=re.IGNORECASE).strip()

    # 2. Try standard json.loads
    try:
        return json.loads(clean)
    except Exception as e1:
        # 3. Fallback: Try repairing raw unescaped newlines/tabs
        try:
            repaired = clean.replace("\r\n", "\\n").replace("\n", "\\n").replace("\r", "\\n")
            return json.loads(repaired)
        except Exception:
            pass

        # 4. Robust Field Regex Extraction fallback
        try:
            def get_field(pattern):
                m = re.search(pattern, clean, flags=re.DOTALL)
                return m.group(1).strip() if m else ""

            title_match = re.search(r'"title"\s*:\s*"([\s\S]*?)"\s*,\s*"(?:date|displayDate|tags|sourceUrl|wordCount|summaryHtml)"', clean)
            title = title_match.group(1).strip() if title_match else get_field(r'"title"\s*:\s*"([^"\r\n]+)"')

            date_val = get_field(r'"date"\s*:\s*"(\d{4}-\d{2}-\d{2})"')
            display_date = get_field(r'"displayDate"\s*:\s*"([^"\r\n]+)"')
            source_url = get_field(r'"sourceUrl"\s*:\s*"(https?://[^"\s]+)"')

            tags = []
            tags_match = re.search(r'"tags"\s*:\s*\[([\s\S]*?)\]', clean)
            if tags_match:
                raw_tags = tags_match.group(1).split(",")
                for t in raw_tags:
                    cleaned_t = t.strip().strip('"').strip("'").strip()
                    if cleaned_t:
                        tags.append(cleaned_t)

            wc_match = re.search(r'"wordCount"\s*:\s*(\d+)', clean)
            word_count = int(wc_match.group(1)) if wc_match else 0

            summary_html = ""
            summary_match = re.search(r'"summaryHtml"\s*:\s*"([\s\S]*?)"\s*(?:,\s*"[a-zA-Z0-9]+"|\s*\})', clean)
            if summary_match:
                summary_html = summary_match.group(1)
            else:
                s_idx = clean.find('"summaryHtml"')
                if s_idx != -1:
                    first_q = clean.find('"', s_idx + 13)
                    last_q = clean.rfind('"')
                    if first_q != -1 and last_q > first_q:
                        summary_html = clean[first_q + 1:last_q]

            if summary_html:
                summary_html = (
                    summary_html.replace('\\"', '"')
                    .replace('\\n', '\n')
                    .replace('\\t', '\t')
                    .replace('\\\\', '\\')
                )

            if title or summary_html:
                return {
                    "title": title or "Unbekannter Titel",
                    "date": date_val or datetime.now().strftime("%Y-%m-%d"),
                    "displayDate": display_date or "",
                    "sourceUrl": source_url or "",
                    "tags": tags if tags else ["Meta-Trends"],
                    "wordCount": word_count,
                    "summaryHtml": summary_html
                }
        except Exception:
            pass

        raise e1

def sleep_with_countdown(seconds, reason_msg):
    for left in range(seconds, 0, -1):
        print(f"\r  {reason_msg} ({left}s verbleibend...)", end="", flush=True)
        time.sleep(1)
    print("\r" + " " * 80 + "\r", end="", flush=True)

def call_gemini_single(prompt_text, api_key, model="gemini-2.0-flash", reminder=None):
    url = f"https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent?key={api_key}"
    system_instruction = (
        "Du bist ein präziser Fakten-Analyst und Fachredakteur für die Newsletter und Substack-Artikel von Peter H. Diamandis. "
        "Aufgabe: Fasse diesen Text/Substack Artikel fundiert, detailliert und tiefgehend auf Deutsch zusammen. "
        "Zielumfang: 570–700 Wörter Umfang, immer zwingend mehr als 500 Wörter (Bereich: 501–750 Wörter). Fokus auf konkrete Fakten, Zahlen, Prognosen, Zitate, Mechanismen und Belege statt vager Zusammenfassungen. "
        "Wähle 2-4 passende Tags AUSSCHLIESSLICH aus folgender Taxonomie: ['KI / AGI', 'KI-Agenten & Software', 'Hardware & Compute', 'Robotik & Humanoide', 'Raumfahrt & Moonshots', 'Energie & Stromnetz', 'Langlebigkeit & Gesundheit', 'Singularität & Exponential Tech', 'Wirtschaft & Organisation', 'Zukunft der Arbeit', 'Sinn & Philosophie', 'Abundance & Wohlstand', 'Bildung & Lernen', 'Mobilität & Autonomie', 'Neurotech & BCI', 'Metaverse & Spatial Computing', 'Daten & Sensorik', 'Materialwissenschaften', 'Umwelt & Planet', 'Geopolitik & Regulierung', 'Finanzen & Krypto', 'Meta-Trends'].\n\n"
        "STRUKTURVORGABE FÜR DIE ZUSAMMENFASSUNG (summaryHtml):\n"
        "1. Einleitung (ca. 100-130 Wörter in 2 Absätzen <p>...</p><p>...</p>):\n"
        "   - 1. Absatz: Historischer/technologischer Kontext, Ausgangslage und Dringlichkeit des Themas.\n"
        "   - 2. Absatz: Zentrale Hypothese, Moonshot-Vision und Kernargument von Peter Diamandis.\n\n"
        "2. Fakten & Kernaussagen (ca. 400-500 Wörter in 5 bis 7 umfangreichen Listenpunkten):\n"
        "   - Formatiert als: <p><strong>Fakten & Kernaussagen:</strong></p><ul class='proof-list'><li class='proof-item'><strong>1. [Thema/Aspekt]:</strong> [Ausführliche Erklärung in mindestens 3-5 vollständigen Sätzen]</li>...</ul>\n"
        "   - ZWINGEND 5 bis 7 eigenständige Punkte (nicht weniger als 5 Punkte!).\n"
        "   - Jeder Punkt muss mindestens 70–90 Wörter umfassen und alle im Text genannten konkreten Zahlen, Jahreszahlen, Prozentwerte, Dollarbeträge, Unternehmensnamen, Hardware-Spezifikationen, Studien und Zitate detailliert darlegen.\n\n"
        "3. Strategischer Ausblick & Fazit (ca. 100-130 Wörter in 2 Absätzen <p>...</p><p>...</p>):\n"
        "   - 1. Absatz: Konsequenzen für Märkte, Geschäftsmodelle, Investoren und die Arbeitswelt.\n"
        "   - 2. Absatz: Handlungsempfehlungen, exponentielle Chancen oder ethische/gesellschaftliche Herausforderungen.\n\n"
        "WICHTIGSTE QUALITÄTSREGEL - MINDESTLÄNGE:\n"
        "Die Zusammenfassung im Feld summaryHtml MUSS ZWINGEND mindestens 501 Wörter umfassen (Ziel: 570–700 Wörter). Bei unter 500 Wörtern gilt die Ausgabe als unzureichend und wird verworfen. Vermeide knappe Stichpunkte oder oberflächliche Verkürzungen. Schreibe jeden Punkt analytisch und gehaltvoll aus.\n\n"
        "WICHTIGE SYNTAX-REGELN FÜR VALIDES JSON:\n"
        "- Verwende für alle HTML-Attribute einfache Anführungszeichen (z. B. class='proof-list' und class='proof-item').\n"
        "- Verwende für Zitate im Fließtext deutsche Anführungszeichen („...“) oder einfache Anführungszeichen ('...'), NIEMALS unmaskierte doppelte englische Anführungszeichen (\").\n"
        "- Schreibe den gesamten HTML-Code von summaryHtml als eine einzige zusammenhängende Textzeile ohne unmaskierte Roh-Zeilenumbrüche im JSON-Wert.\n\n"
        "Antworte AUSSCHLIESSLICH mit einem JSON-Objekt im Format:\n"
        "{\n"
        '  "title": "Prägnanter Titel des Artikels",\n'
        '  "date": "YYYY-MM-DD",\n'
        '  "displayDate": "DD. Monat YYYY",\n'
        '  "sourceUrl": "https://metatrends.substack.com/p/... (oder gefundener Link)",\n'
        '  "tags": ["Tag1", "Tag2", "Tag3"],\n'
        '  "wordCount": 620,\n'
        '  "summaryHtml": "<p>Einleitung Absatz 1: Kontext und Ausgangslage...</p><p>Einleitung Absatz 2: Zentrale These von Peter Diamandis...</p><p><strong>Fakten & Kernaussagen:</strong></p><ul class=\'proof-list\'><li class=\'proof-item\'><strong>1. Thema:</strong> Detaillierte Ausführung mit Zahlen und Fakten...</li><li class=\'proof-item\'><strong>2. Thema:</strong> Detaillierte Ausführung mit Zahlen und Fakten...</li><li class=\'proof-item\'><strong>3. Thema:</strong> Detaillierte Ausführung mit Zahlen und Fakten...</li><li class=\'proof-item\'><strong>4. Thema:</strong> Detaillierte Ausführung mit Zahlen und Fakten...</li><li class=\'proof-item\'><strong>5. Thema:</strong> Detaillierte Ausführung mit Zahlen und Fakten...</li><li class=\'proof-item\'><strong>6. Thema:</strong> Detaillierte Ausführung mit Zahlen und Fakten...</li></ul><p><strong>Fazit & Ausblick:</strong> Fazit Absatz 1 zu Marktauswirkungen...</p><p>Ausblick Absatz 2 zu Zukunftschancen und Handlungsfeldern...</p>"\n'
        "}"
    )
    contents = [
        {"parts": [{"text": system_instruction}, {"text": prompt_text}]}
    ]
    if reminder:
        contents.append({"parts": [{"text": reminder}]})

    req_body = {
        "contents": contents,
        "generationConfig": {
            "temperature": 0.2,
            "responseMimeType": "application/json"
        }
    }
    req = urllib.request.Request(
        url,
        data=json.dumps(req_body).encode("utf-8"),
        headers={"Content-Type": "application/json"}
    )
    try:
        with urllib.request.urlopen(req) as resp:
            res_data = json.loads(resp.read().decode("utf-8"))
            res_text = res_data["candidates"][0]["content"]["parts"][0]["text"]
            return safe_parse_gemini_json(res_text)
    except urllib.error.HTTPError as he:
        err_body = he.read().decode("utf-8", errors="ignore")
        raise Exception(f"HTTP {he.code}: {err_body}")

def call_gemini_with_overload_handling(prompt_text, key_manager, models=None, reminder=None):
    if not isinstance(key_manager, GeminiKeyManager):
        key_manager = GeminiKeyManager([key_manager] if key_manager else [])
    
    if not key_manager.has_keys:
        raise ValueError("Kein GEMINI_API_KEY vorhanden! Bitte mit --key=... oder als Umgebungsvariable übergeben.")

    if not models:
        models = ["gemini-2.0-flash", "gemini-2.0-flash-lite", "gemini-1.5-flash", "gemini-2.5-pro"]
    elif isinstance(models, str):
        models = [m.strip() for m in models.split(",") if m.strip()]

    total_keys = key_manager.total

    for m_idx in range(len(models)):
        current_model = models[m_idx]
        is_default_model = (m_idx == 0)
        is_pro = "pro" in current_model.lower()
        model_label = f"Default: {current_model}" if is_default_model else f"Fallback {m_idx}: {current_model}{' (Pro)' if is_pro else ''}"

        keys_tried_for_this_model = 0
        while keys_tried_for_this_model < total_keys:
            active_key = key_manager.get_active_key()
            active_tag = key_manager.get_active_tag()

            try:
                print(f"  -> {active_tag} Sende Anfrage an [{model_label}]...")
                return call_gemini_single(prompt_text, active_key, model=current_model, reminder=reminder)
            except Exception as err1:
                # 0a. Modell nicht gefunden / nicht unterstützt (404 / unsupported)
                if is_model_not_found_error(err1):
                    print(f"\n  [!] Modell [{model_label}] existiert nicht oder wird von API nicht unterstützt ({err1}).")
                    if m_idx < len(models) - 1:
                        next_model = models[m_idx + 1]
                        next_is_pro = "pro" in next_model.lower()
                        next_label = f"Fallback {m_idx + 1}: {next_model}{' (Pro)' if next_is_pro else ''}"
                        print(f"  -> Wechsle sofort zu [{next_label}]...")
                        break
                    else:
                        raise Exception(f"Keines der Modelle ({', '.join(models)}) wird von der Gemini API unterstützt.")

                # 0b. JSON Syntaxfehler vom Modell -> Wechsle zum nächsten Modell in der Kaskade
                if is_json_syntax_error(err1):
                    print(f"\n  [!] Modell [{model_label}] lieferte ungültiges JSON ({err1}).")
                    if m_idx < len(models) - 1:
                        next_model = models[m_idx + 1]
                        next_is_pro = "pro" in next_model.lower()
                        next_label = f"Fallback {m_idx + 1}: {next_model}{' (Pro)' if next_is_pro else ''}"
                        print(f"  -> Wechsle zu [{next_label}]...")
                        break
                    else:
                        raise Exception(f"Alle Modelle ({', '.join(models)}) lieferten ungültige JSON-Antworten. ({err1})")

                # 1. Quota Limit für diesen Key auf diesem Modell
                if is_quota_error(err1):
                    keys_tried_for_this_model += 1
                    if keys_tried_for_this_model < total_keys:
                        key_manager.rotate_key()
                        continue  # Nächsten Key für DIESES Modell testen!
                    else:
                        # Alle Keys für dieses Modell erschöpft
                        if m_idx < len(models) - 1:
                            next_model = models[m_idx + 1]
                            next_is_pro = "pro" in next_model.lower()
                            next_label = f"Fallback {m_idx + 1}: {next_model}{' (Pro)' if next_is_pro else ''}"
                            print(f"\n  [!] Quota für alle {total_keys} Keys auf [{model_label}] erschöpft. Wechsle zu [{next_label}]...")
                            break  # Verlasse while-Schleife -> for-Schleife geht zu nächstem Modell (m_idx + 1)
                        else:
                            raise Exception(f"Kontingentlimit (Quota) für alle {len(models)} Modelle ({', '.join(models)}) auf allen {total_keys} API Keys erreicht.")

                if not is_overload_error(err1):
                    raise err1

                # 2. Überlastung
                print(f"\n  [!] {active_tag} [{model_label}] überlastet (High Demand). Warte 15 Sekunden...")
                if is_default_model:
                    # 1. Versuch Default überlastet -> 15s Pause vor 2. Versuch
                    sleep_with_countdown(15, f"{active_tag} Warte vor 2. Versuch mit {current_model}")

                    # 2. Versuch mit selbem Default-Modell
                    try:
                        active_key = key_manager.get_active_key()
                        active_tag = key_manager.get_active_tag()
                        print(f"  -> {active_tag} 2. Versuch mit [{model_label}]...")
                        return call_gemini_single(prompt_text, active_key, model=current_model, reminder=reminder)
                    except Exception as err2:
                        if is_model_not_found_error(err2):
                            if m_idx < len(models) - 1:
                                break
                            else:
                                raise Exception(f"Keines der Modelle wird von der API unterstützt.")

                        if is_json_syntax_error(err2):
                            print(f"\n  [!] Modell [{model_label}] lieferte erneut ungültiges JSON ({err2}).")
                            if m_idx < len(models) - 1:
                                break
                            else:
                                raise Exception(f"Alle Modelle lieferten ungültiges JSON.")

                        if is_quota_error(err2):
                            keys_tried_for_this_model += 1
                            if keys_tried_for_this_model < total_keys:
                                key_manager.rotate_key()
                                continue
                            else:
                                if m_idx < len(models) - 1:
                                    next_model = models[m_idx + 1]
                                    next_is_pro = "pro" in next_model.lower()
                                    next_label = f"Fallback {m_idx + 1}: {next_model}{' (Pro)' if next_is_pro else ''}"
                                    print(f"\n  [!] Quota für alle {total_keys} Keys auf [{model_label}] erschöpft. Wechsle zu [{next_label}]...")
                                    break
                                else:
                                    raise Exception(f"Kontingentlimit für alle {len(models)} Modelle auf allen {total_keys} Keys erreicht.")

                        if not is_overload_error(err2):
                            raise err2

                        # 2. Versuch Default erneut überlastet -> 15s Pause vor Wechsel zum nächsten Fallback
                        if m_idx < len(models) - 1:
                            next_model = models[m_idx + 1]
                            next_is_pro = "pro" in next_model.lower()
                            next_label = f"Fallback {m_idx + 1}: {next_model}{' (Pro)' if next_is_pro else ''}"
                            print(f"\n  [!] {active_tag} [{model_label}] erneut überlastet. Warte 15s vor Wechsel zu [{next_label}]...")
                            sleep_with_countdown(15, f"{active_tag} Warte vor Wechsel zu {next_model}")
                            break
                        else:
                            raise Exception(f"Alle {len(models)} Modelle ({', '.join(models)}) sind überlastet. ({err2})")
                else:
                    # Bei Fallback-Modellen (m_idx > 0): Wenn überlastet, 15s Pause vor nächstem Fallback!
                    if m_idx < len(models) - 1:
                        next_model = models[m_idx + 1]
                        next_is_pro = "pro" in next_model.lower()
                        next_label = f"Fallback {m_idx + 1}: {next_model}{' (Pro)' if next_is_pro else ''}"
                        print(f"\n  [!] {active_tag} [{model_label}] überlastet. Warte 15s vor Wechsel zu [{next_label}]...")
                        sleep_with_countdown(15, f"{active_tag} Warte vor Wechsel zu {next_model}")
                        break
                    else:
                        raise Exception(f"Alle {len(models)} Modelle ({', '.join(models)}) sind überlastet. ({err1})")

    raise Exception("Kein funktionierendes Modell/Key verfügbar.")

def summarize_with_retry(full_text, key_manager, models=None, min_words=500):
    base_prompt = (
        "Fasse diesen Text/Substack Artikel zusammen. Zielumfang: 570-700 Wörter, zwingend mehr als 500 Wörter (Range: 501-750 Wörter), "
        "mindestens 5-7 ausführliche Kernaussagen mit konkreten Daten/Zahlen/Zitaten, Fokus auf Fakten statt Storytelling, extrahiere den enthaltenen Substack.com Link, "
        "wähle 2-4 passende Tags AUSSCHLIESSLICH aus folgender Taxonomie: ['KI / AGI', 'KI-Agenten & Software', 'Hardware & Compute', 'Robotik & Humanoide', 'Raumfahrt & Moonshots', 'Energie & Stromnetz', 'Langlebigkeit & Gesundheit', 'Singularität & Exponential Tech', 'Wirtschaft & Organisation', 'Zukunft der Arbeit', 'Sinn & Philosophie', 'Abundance & Wohlstand', 'Bildung & Lernen', 'Mobilität & Autonomie', 'Neurotech & BCI', 'Metaverse & Spatial Computing', 'Daten & Sensorik', 'Materialwissenschaften', 'Umwelt & Planet', 'Geopolitik & Regulierung', 'Finanzen & Krypto', 'Meta-Trends'].\n\n"
        f"Text:\n{full_text}"
    )
    
    result = call_gemini_with_overload_handling(base_prompt, key_manager, models=models)
    words = count_words(result.get("summaryHtml", "") if result else "")
    retries = 0
    
    while (words < min_words or words > 750) and retries < 2:
        retries += 1
        print(f"  [Wortzahl: {words} Wörter - außerhalb gefordertem Bereich (>= {min_words} Wörter)! Erinnerung & Retry {retries}/2...]")
        if words < min_words:
            previous_draft = result.get("summaryHtml", "") if result else ""
            retry_prompt = (
                f"⚠️ WICHTIGER QUALITÄTSHINWEIS: Dein vorheriger Entwurf hatte leider nur {words} Wörter und hat damit die geforderte Mindestlänge von {min_words} Wörtern verfehlt (Zwingend gefordert: mindestens 501 Wörter, Ziel: 570–700 Wörter).\n\n"
                f"Hier ist dein bisheriger Textentwurf:\n\"\"\"\n{previous_draft}\n\"\"\"\n\n"
                "AUFTRAG ZUR ERWEITERUNG:\n"
                "Bitte nimm diesen bisherigen Entwurf als Basis und ERWEITERE ihn substanziell auf mindestens 570–700 Wörter:\n"
                "1. Ergänze 1–2 weitere detaillierte Kernaussagen (<li class='proof-item'>) mit bisher ausgelassenen Daten, Zahlen, Zitaten oder Thesen aus dem Originaltext (sodass insgesamt 5 bis 7 Kernpunkte vorhanden sind).\n"
                "2. Baue JEDEN bestehenden Listenpunkt um 1–2 zusätzliche erklärende Sätze mit konkreten Mechanismen, Beispielen und Hintergründen aus (jeder Punkt mind. 70-90 Wörter).\n"
                "3. Vertiefe Einleitung und Fazit auf jeweils 2 fundierte, gehaltvolle Absätze (<p>...</p><p>...</p>).\n"
                "Antworte wieder ausschließlich mit dem vollständigen, validen JSON-Objekt im vorgegebenen Format."
            )
        else:
            retry_prompt = (
                f"⚠️ HINWEIS: Dein vorheriger Entwurf hatte {words} Wörter und überschreitet die Maximallänge von 750 Wörtern (Zielumfang: 570–700 Wörter). "
                "Bitte straffe Füllwörter und kürze den Text maßvoll, sodass er im Bereich von 570–700 Wörtern liegt. Antworte mit dem vollständigen, validen JSON-Objekt."
            )
        try:
            result = call_gemini_with_overload_handling(base_prompt, key_manager, models=models, reminder=retry_prompt)
            words = count_words(result.get("summaryHtml", "") if result else "")
        except Exception as e:
            print(f"  [!] Retry-Fehler: {e}")
            break
            
    # Strikte Prüfung ohne Kompromiss (< min_words wird strikt verworfen):
    if words < min_words:
        raise ValueError(f"Zusammenfassung hat nur {words} Wörter (< {min_words} Wörter gefordert). Artikel wird als ungültig verworfen.")

    print(f"  -> Finale Wortanzahl: {words} Wörter (Gültig >= {min_words})")
    result["wordCount"] = words
    return result

def is_promotional_image(img_tag_html, preceding_headings=None, parent_href=""):
    """
    Prüft, ob ein Bild eines der wiederkehrenden Werbe-Bilder ist:
    1. 'We Are As Gods' Buch-Werbung
    2. 'Moonshots' Podcast-Werbung
    3. 'Abundance360' / 'A360' Community-Werbung
    """
    if preceding_headings is None:
        preceding_headings = []

    data_attrs_m = re.search(r'data-attrs="([^"]+)"', img_tag_html)
    data_attrs = {}
    if data_attrs_m:
        raw_json = data_attrs_m.group(1).replace('&quot;', '"')
        try:
            data_attrs = json.loads(raw_json)
        except Exception:
            pass

    href = (data_attrs.get("href") or parent_href or "").lower()
    alt = (data_attrs.get("alt") or "").lower()
    title = (data_attrs.get("title") or "").lower()
    src = (data_attrs.get("src") or "").lower()

    alt_m = re.search(r'alt="([^"]+)"', img_tag_html, re.IGNORECASE)
    if alt_m and not alt:
        alt = alt_m.group(1).lower()

    src_m = re.search(r'src="([^"]+)"', img_tag_html, re.IGNORECASE)
    if src_m and not src:
        src = src_m.group(1).lower()

    combined_meta = f"{href} {alt} {title} {src}"
    headings_text = " ".join(preceding_headings).lower()

    # Bekannte Werbebild-Hashes / Dateinamen
    known_promo_hashes = [
        "9200e33c-6732-48bd-9ccd-f2c32c3c198a",
        "13c1862b-8a12-4f62-abe4-fe2db11c2190",
        "720c2af3-3d1b-4d3b-8802-f876a8f4ba43",
    ]
    for h in known_promo_hashes:
        if h in combined_meta:
            return True

    # 1. "We Are As Gods" Buch-Werbung
    if "weareasgods" in href or "we-are-as-gods" in href or "weareasgods" in src or "we-are-as-gods" in src:
        return True
    if "we are as gods" in alt or "steven kotler" in alt:
        return True
    if any(p in headings_text for p in ["we are as gods", "pre-order", "preorder"]):
        return True

    # 2. "Moonshots" Podcast-Werbung (unter "More From Peter" / "Stay Connected" oder Link auf YouTube-Kanal)
    if "youtube.com/@peterdiamandis" in href or "diamandis.com/moonshots" in href or "moonshots.diamandis.com" in href:
        return True
    if "moonshots with peter diamandis" in alt or "moonshots podcast" in alt:
        return True
    if "more from peter" in headings_text and ("moonshots" in combined_meta or "youtube" in href):
        return True

    # 3. "Abundance360" / "A360" Community-Werbung
    if any(u in href for u in ["qr.diamandis.com/a360", "abundance360.com", "diamandis.com/a360", "a360-invite"]):
        return True
    if "abundance360" in alt or "abundance 360" in alt or "a360 community" in alt:
        return True
    if "more from peter" in headings_text and ("abundance" in combined_meta or "a360" in href):
        return True

    return False

def download_remote_image(url, target_dir, base_file_name):
    if not url:
        return ""
    os.makedirs(target_dir, exist_ok=True)
    headers = {"User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64)"}
    try:
        req = urllib.request.Request(url, headers=headers)
        with urllib.request.urlopen(req, timeout=15) as resp:
            data = resp.read()
            content_type = resp.headers.get("Content-Type", "").lower()
            ext = ".jpg"
            if "png" in content_type:
                ext = ".png"
            elif "webp" in content_type:
                ext = ".webp"
            elif "gif" in content_type:
                ext = ".gif"
            file_name = f"{base_file_name}{ext}"
            file_path = os.path.join(target_dir, file_name)
            with open(file_path, "wb") as f:
                f.write(data)

            # Mindestgröße prüfen: Breite und Höhe müssen >= 300px sein
            try:
                from PIL import Image
                with Image.open(file_path) as im:
                    w, h = im.size
                    if w < 300 or h < 300:
                        im.close()
                        try:
                            os.remove(file_path)
                        except Exception:
                            pass
                        return ""
            except Exception:
                pass

            rel_path = os.path.relpath(file_path, os.path.dirname(__file__)).replace("\\", "/")
            return rel_path
    except Exception as e:
        print(f"    [!] Bild-Download fehlgeschlagen ({url[:60]}...): {e}")
        return url

def extract_images_from_post(post):
    hero_image_url = (post.get("cover_image") or "").strip()
    context_images = []
    seen_urls = set()

    html_content = post.get("content", "")
    link = post.get("link", "")

    if link:
        try:
            headers = {"User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64)"}
            req = urllib.request.Request(link, headers=headers)
            with urllib.request.urlopen(req, timeout=12) as resp:
                page_html = resp.read().decode("utf-8", errors="ignore")
                if page_html and len(page_html) > 300:
                    html_content = page_html
        except Exception:
            pass

    # Prüfe, ob initiales Cover-Bild ein Werbebild ist
    if hero_image_url and is_promotional_image(f'<img src="{hero_image_url}">'):
        hero_image_url = ""

    if not hero_image_url and html_content:
        og_match = re.search(r'<meta\s+(?:property="og:image"|name="twitter:image")\s+content="([^"]+)"', html_content, re.IGNORECASE)
        if not og_match:
            og_match = re.search(r'<meta\s+content="([^"]+)"\s+(?:property="og:image"|name="twitter:image")', html_content, re.IGNORECASE)
        if og_match:
            og_url = og_match.group(1).strip()
            if "substack-custom-assets" not in og_url and "/default_" not in og_url:
                if not is_promotional_image(f'<img src="{og_url}">'):
                    hero_image_url = og_url
                    seen_urls.add(hero_image_url)

    img_matches = list(re.finditer(r'<img\s+([^>]+)>', html_content, re.IGNORECASE))
    for m in img_matches:
        tag_attrs = m.group(1)
        src_match = re.search(r'src="([^"]+)"', tag_attrs, re.IGNORECASE)
        if not src_match:
            src_match = re.search(r'data-src="([^"]+)"', tag_attrs, re.IGNORECASE)
        if not src_match:
            continue
        src = src_match.group(1).strip()
        if not src or src.startswith("data:") or ".svg" in src or "/pixel." in src or "/track." in src:
            continue
        if "substack-custom-assets" in src or "/author_photos/" in src or "/avatar" in src or "user_avatar" in src:
            continue
        if "/h_72" in src or "/h_60" in src or "email_banner" in src:
            continue
        if "avatar" in tag_attrs or "byline" in tag_attrs:
            continue

        # Prüfe Dimensionen (>= 300px)
        w_m = re.search(r'width="?(\d+)"?', tag_attrs, re.IGNORECASE)
        h_m = re.search(r'height="?(\d+)"?', tag_attrs, re.IGNORECASE)
        w = int(w_m.group(1)) if w_m else 0
        h = int(h_m.group(1)) if h_m else 0

        data_attrs_m = re.search(r'data-attrs="([^"]+)"', tag_attrs)
        if data_attrs_m:
            try:
                da = json.loads(data_attrs_m.group(1).replace('&quot;', '"'))
                if da.get("resizeWidth") and not w: w = int(da["resizeWidth"])
                if da.get("width") and not w: w = int(da["width"])
                if da.get("height") and not h: h = int(da["height"])
            except Exception:
                pass

        if (w > 0 and w < 300) or (h > 0 and h < 300):
            continue

        # Vorhergehende Überschriften ermitteln
        preceding_body = html_content[:m.start()]
        preceding_h = re.findall(r'<h[1-6][^>]*>([\s\S]*?)</h[1-6]>', preceding_body, re.IGNORECASE)
        recent_headings = [re.sub(r'<[^>]+>', ' ', h_tag).strip() for h_tag in preceding_h[-3:]] if preceding_h else []

        parent_a = ""
        a_matches = list(re.finditer(r'<a\s+[^>]*href="([^"]+)"[^>]*>', preceding_body, re.IGNORECASE))
        if a_matches:
            last_a = a_matches[-1]
            after_a = preceding_body[last_a.end():]
            if "</a>" not in after_a:
                parent_a = last_a.group(1)

        # Prüfe Werbe-Bilder
        if is_promotional_image(m.group(0), recent_headings, parent_a):
            continue

        if src in seen_urls:
            continue
        seen_urls.add(src)

        if not hero_image_url:
            hero_image_url = src
            continue

        alt_match = re.search(r'alt="([^"]+)"', tag_attrs, re.IGNORECASE)
        alt_text = alt_match.group(1).strip() if alt_match else f"Abbildung {len(context_images) + 1}"

        context_images.append({
            "url": src,
            "alt": alt_text
        })

    if hero_image_url and hero_image_url not in seen_urls:
        seen_urls.add(hero_image_url)

    return hero_image_url, context_images

def fetch_all_substack_posts():
    all_posts = []
    headers = {"User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64)"}
    # Fetch paginated Substack API (offsets 0 and 50 give 100 historical posts)
    for offset in [0, 50]:
        url = f"https://metatrends.substack.com/api/v1/posts?sort=new&limit=50&offset={offset}"
        try:
            req = urllib.request.Request(url, headers=headers)
            with urllib.request.urlopen(req, timeout=12) as resp:
                data = json.loads(resp.read().decode("utf-8"))
                if isinstance(data, list) and data:
                    all_posts.extend(data)
        except Exception as e:
            print(f"Hinweis beim Abrufen von Substack API (offset={offset}): {e}")
            break

    if all_posts:
        return [
            {
                "title": (p.get("title") or "").strip(),
                "link": p.get("canonical_url") or f"https://metatrends.substack.com/p/{p.get('slug')}",
                "pub_date": p.get("post_date") or "",
                "content": p.get("body_html") or p.get("description") or "",
                "slug": p.get("slug") or "",
                "cover_image": p.get("cover_image") or ""
            }
            for p in all_posts
            if not is_post_ignored(p.get("canonical_url") or p.get("slug") or p.get("title"))
        ]

    # Fallback to RSS feed
    print("Nutze RSS-Feed als Fallback...")
    req = urllib.request.Request("https://metatrends.substack.com/feed", headers=headers)
    with urllib.request.urlopen(req) as resp:
        xml_content = resp.read()
    root = ET.fromstring(xml_content)
    fallback = []
    for item in root.findall(".//item"):
        title = item.findtext("title", "").strip()
        link = item.findtext("link", "").strip()
        pub_date = item.findtext("pubDate", "").strip()
        content_el = item.find("{http://purl.org/rss/1.0/modules/content/}encoded")
        content = content_el.text if content_el is not None else item.findtext("description", "")
        if title and not is_post_ignored(link or title):
            fallback.append({
                "title": title,
                "link": link,
                "pub_date": pub_date,
                "content": content or "",
                "slug": "",
                "cover_image": ""
            })
    return fallback

def scan_substack(key_manager=None, max_articles=6, models=None, images_only_mode=False, scan_mode="all"):
    if not isinstance(key_manager, GeminiKeyManager):
        key_manager = GeminiKeyManager(key_manager)

    if not models:
        models = ["gemini-2.0-flash", "gemini-2.0-flash-lite", "gemini-1.5-flash", "gemini-2.5-pro"]
    elif isinstance(models, str):
        models = [m.strip() for m in models.split(",") if m.strip()]

    print(f"Scanne Substack Archiv (metatrends.substack.com)...")
    substack_posts = fetch_all_substack_posts()
    valid_substack_posts = [p for p in substack_posts if not is_post_ignored(p)]
    valid_substack_posts.sort(key=lambda x: x.get("pub_date", ""), reverse=True)
    print(f"-> {len(valid_substack_posts)} nicht-ignorierte Artikel auf Substack gefunden.")

    existing = load_data()

    existing_tasks = []
    new_tasks = []
    short_count = 0
    images_only_count = 0
    new_count = 0

    for post in valid_substack_posts:
        match = None
        for it in existing:
            if is_post_ignored(it):
                continue
            it_url = (it.get("sourceUrl") or "").strip().lower()
            p_link = (post.get("link") or "").strip().lower()
            if it_url and p_link and it_url == p_link:
                match = it
                break
            p_slug = (post.get("slug") or "").strip().lower()
            if p_slug and f"/p/{p_slug}" in it_url:
                match = it
                break
            it_title = (it.get("title") or "").strip().lower()
            p_title = (post.get("title") or "").strip().lower()
            if it_title and p_title and it_title == p_title:
                match = it
                break

        if not match:
            if not images_only_mode and scan_mode in ("all", "new"):
                new_count += 1
                new_tasks.append({
                    "action": "create",
                    "existing": None,
                    "post": post,
                    "old_words": 0,
                    "min_required": 500
                })
        else:
            if scan_mode in ("all", "existing"):
                words = match.get("wordCount") or count_words(match.get("summaryHtml", ""))
                min_required = get_required_word_count(match)
                has_valid_length = (words >= min_required)
                has_images_checked = (match.get("imagesChecked") is True)
                has_existing_images = bool(match.get("heroImage") or match.get("images"))

                if not has_valid_length:
                    if not images_only_mode:
                        short_count += 1
                        existing_tasks.append({
                            "action": "update_full",
                            "existing": match,
                            "post": post,
                            "old_words": words,
                            "min_required": min_required
                        })
                elif not has_images_checked or has_existing_images:
                    images_only_count += 1
                    existing_tasks.append({
                        "action": "images_only",
                        "existing": match,
                        "post": post,
                        "old_words": words,
                        "min_required": min_required
                    })

    if scan_mode == "existing":
        tasks_queue = existing_tasks
    elif scan_mode == "new":
        tasks_queue = new_tasks
    else:
        tasks_queue = existing_tasks + new_tasks

    total_tasks = len(tasks_queue)
    print(f"-> Prüfung abgeschlossen: {total_tasks} Aufgaben in Warteschlange ({short_count} Text-Überarbeitung, {images_only_count} nur Bilder laden, {new_count} neu).")

    if total_tasks == 0:
        print("[OK] Alle gewünschten Aufgaben sind erfasst und erfüllen die Qualitätsanforderungen!")
        return

    to_process = tasks_queue if max_articles <= 0 else tasks_queue[:max_articles]
    prio_msg = f" (zuerst {len(existing_tasks)} bestehende Artikel prüfen/bereinigen, danach neue Artikel)" if (scan_mode == "all" and existing_tasks) else ""
    print(f"-> Starte Batch von {len(to_process)} Artikeln{prio_msg}:\n")

    needs_ai = any(t["action"] in ("create", "update_full") for t in to_process)
    if needs_ai and not key_manager.has_keys:
        print("[Hinweis] Kein GEMINI_API_KEY vorhanden. KI-Textgenerierung wird übersprungen – Bilderscan & Download für alle bestehenden Artikel wird dennoch vollständig durchgeführt!")

    processed_in_batch = 0
    try:
        for i, task in enumerate(to_process, 1):
            post = task["post"]

            if task["action"] == "images_only":
                print(f"[{i}/{len(to_process)}] [🖼️ Nur Bilder] '{post['title']}' (Text hat bereits {task['old_words']} W.)...")
                hero_raw, context_raw = extract_images_from_post(post)

                art_id = task["existing"].get("id") or re.sub(r"[^a-z0-9]+", "-", post["title"].lower())[:30]
                target_dir = os.path.join(os.path.dirname(__file__), "images", art_id)

                hero_saved = download_remote_image(hero_raw, target_dir, "hero") if hero_raw else ""
                context_saved = []
                for idx, cimg in enumerate(context_raw, 1):
                    saved_url = download_remote_image(cimg["url"], target_dir, f"image-{idx}")
                    if saved_url:
                        context_saved.append({
                            "url": saved_url,
                            "alt": cimg.get("alt", f"Abbildung {idx}")
                        })

                # Bereinige verwaiste oder gelöschte Bilder im Ordner
                saved_filenames = set()
                if hero_saved:
                    saved_filenames.add(os.path.basename(hero_saved))
                for cs in context_saved:
                    saved_filenames.add(os.path.basename(cs["url"]))

                if os.path.exists(target_dir):
                    try:
                        for f in os.listdir(target_dir):
                            fp = os.path.join(target_dir, f)
                            if os.path.isfile(fp) and f not in saved_filenames:
                                os.remove(fp)
                        if not os.listdir(target_dir):
                            os.rmdir(target_dir)
                    except Exception:
                        pass

                task["existing"]["heroImage"] = hero_saved or None
                task["existing"]["images"] = context_saved
                task["existing"]["imagesChecked"] = True
                print(f"  -> Bilder gespeichert (Hero: {'Ja' if hero_saved else 'Nein'}, Kontext: {len(context_saved)}).")
                processed_in_batch += 1

            else:
                target_min_words = task["min_required"]
                is_update = (task["action"] == "update_full")
                action_name = f"Überarbeite Text (< {target_min_words} Wörter)" if is_update else "Neu erfassen (>= 500 Wörter)"
                print(f"[{i}/{len(to_process)}] {key_manager.get_active_tag()} {action_name}: '{post['title']}'...")

                clean_text = re.sub(r"<[^>]+>", " ", post["content"])[:16000]
                full_text = f"Titel: {post['title']}\nDatum: {post['pub_date']}\nSubstack Link: {post['link']}\n\nInhalt:\n{clean_text}"

                ai_summary_succeeded = False
                result = None
                ai_err_msg = ""

                if key_manager.has_keys:
                    try:
                        result = summarize_with_retry(full_text, key_manager, models=models, min_words=500)
                        ai_summary_succeeded = True
                    except Exception as e:
                        print(f"  [!] KI-Zusammenfassung fehlgeschlagen: {e}")
                        ai_err_msg = str(e)
                else:
                    ai_err_msg = "Kein GEMINI_API_KEY hinterlegt"

                # Unkonditionales Bilderscraping & Download
                slug = re.sub(r"[^a-z0-9]+", "-", (result.get("title") if result else post["title"]).lower())[:30]
                art_id = task["existing"].get("id") if is_update else f"{(result.get('date') if result else '2026-01-01')}-{slug}"

                hero_raw, context_raw = extract_images_from_post(post)
                target_dir = os.path.join(os.path.dirname(__file__), "images", art_id)

                hero_saved = download_remote_image(hero_raw, target_dir, "hero") if hero_raw else ""
                context_saved = []
                for idx, cimg in enumerate(context_raw, 1):
                    saved_url = download_remote_image(cimg["url"], target_dir, f"image-{idx}")
                    if saved_url:
                        context_saved.append({
                            "url": saved_url,
                            "alt": cimg.get("alt", f"Abbildung {idx}")
                        })

                # Bereinige verwaiste oder gelöschte Bilder im Ordner
                saved_filenames = set()
                if hero_saved:
                    saved_filenames.add(os.path.basename(hero_saved))
                for cs in context_saved:
                    saved_filenames.add(os.path.basename(cs["url"]))

                if os.path.exists(target_dir):
                    try:
                        for f in os.listdir(target_dir):
                            fp = os.path.join(target_dir, f)
                            if os.path.isfile(fp) and f not in saved_filenames:
                                os.remove(fp)
                        if not os.listdir(target_dir):
                            os.rmdir(target_dir)
                    except Exception:
                        pass

                if is_update:
                    existing_item = task["existing"]
                    if ai_summary_succeeded and result:
                        existing_item["title"] = result.get("title", existing_item["title"])
                        existing_item["displayDate"] = result.get("displayDate", existing_item["displayDate"])
                        existing_item["sourceUrl"] = result.get("sourceUrl") or post["link"] or existing_item.get("sourceUrl")
                        existing_item["tags"] = result.get("tags", existing_item.get("tags", ["Tech"]))
                        existing_item["wordCount"] = result.get("wordCount", count_words(result.get("summaryHtml", "")))
                        existing_item["summaryHtml"] = result.get("summaryHtml", existing_item.get("summaryHtml"))
                    
                    existing_item["heroImage"] = hero_saved or None
                    existing_item["images"] = context_saved
                    existing_item["imagesChecked"] = True

                    if ai_summary_succeeded:
                        print(f"  -> Erfolgreich überschrieben ({existing_item['wordCount']} W., {len(context_saved) + (1 if hero_saved else 0)} Bilder).")
                    else:
                        print(f"  -> [Bilder-Fallback] Text-Überarbeitung übersprungen ({ai_err_msg}), aber {len(context_saved) + (1 if hero_saved else 0)} Bilder erfolgreich geladen & gespeichert.")
                    processed_in_batch += 1

                else:
                    if ai_summary_succeeded and result:
                        entry = {
                            "id": art_id,
                            "title": result.get("title", post["title"]),
                            "date": result.get("date", "2026-01-01"),
                            "displayDate": result.get("displayDate", post["pub_date"]),
                            "sourceUrl": result.get("sourceUrl") or post["link"],
                            "sourceType": "substack",
                            "tags": result.get("tags", ["Tech"]),
                            "wordCount": result.get("wordCount", count_words(result.get("summaryHtml", ""))),
                            "summaryHtml": result.get("summaryHtml", f"<p>{post['title']}</p>"),
                            "heroImage": hero_saved,
                            "images": context_saved,
                            "imagesChecked": True
                        }
                        existing.append(entry)
                        print(f"  -> Neu gespeichert ({entry['wordCount']} W., {len(context_saved) + (1 if hero_saved else 0)} Bilder).")
                        processed_in_batch += 1
                    else:
                        print(f"  [!] Neuer Artikel übersprungen ({ai_err_msg}): '{post['title']}'")

    except KeyboardInterrupt:
        print("\n\n[!] Abbruch durch Benutzer angefordert. Speichere bisherigen Fortschritt...")

    save_data(existing)
    remaining = total_tasks - processed_in_batch
    if remaining > 0:
        print(f"\n[Info] {processed_in_batch} Artikel verarbeitet. Noch {remaining} Aufgaben in der Warteschlange.")
    else:
        print(f"\n[Fertig] Alle Artikel aus dem Archiv wurden erfolgreich erfasst und mit Bildern ausgestattet!")

if __name__ == "__main__":
    raw_keys = []
    env_keys = os.environ.get("GEMINI_API_KEYS") or os.environ.get("GEMINI_API_KEY")
    if env_keys:
        try:
            parsed = json.loads(env_keys)
            if isinstance(parsed, list):
                raw_keys.extend(parsed)
            else:
                raw_keys.extend(str(env_keys).split(","))
        except Exception:
            raw_keys.extend(str(env_keys).split(","))

    models = ["gemini-2.0-flash", "gemini-2.0-flash-lite", "gemini-1.5-flash", "gemini-2.5-pro"]
    env_models = os.environ.get("GEMINI_MODELS") or os.environ.get("GEMINI_MODEL")
    if env_models:
        models = [m.strip() for m in env_models.split(",") if m.strip()]

    max_articles = 6
    images_only_mode = False
    scan_mode = "all"

    for arg in sys.argv:
        if arg.startswith("--key="):
            raw_keys.append(arg.split("=", 1)[1])
        elif arg.startswith("--keys="):
            raw_keys.extend(arg.split("=", 1)[1].split(","))
        elif arg.startswith("--models="):
            models = [m.strip() for m in arg.split("=", 1)[1].split(",") if m.strip()]
        elif arg.startswith("--model="):
            models[0] = arg.split("=", 1)[1]
        elif arg.startswith("--limit="):
            try:
                max_articles = int(arg.split("=", 1)[1])
            except ValueError:
                pass
        elif arg == "--unlimited":
            max_articles = -1
        elif arg == "--images-only":
            images_only_mode = True
        elif arg in ("--existing-only", "--existing"):
            scan_mode = "existing"
        elif arg in ("--new-only", "--new"):
            scan_mode = "new"

    key_manager = GeminiKeyManager(raw_keys)
    scan_substack(key_manager=key_manager, max_articles=max_articles, models=models, images_only_mode=images_only_mode, scan_mode=scan_mode)

