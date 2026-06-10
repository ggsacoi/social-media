# moderate_video.py
import cv2
import sys
import json
import os

def moderate_video(video_path):
    if not os.path.exists(video_path):
        return {"safe": False, "reason": "Le fichier vidéo n'existe pas."}

    # Import retardé de nudenet pour éviter les lenteurs au démarrage s'il n'est pas utilisé
    try:
        from nudenet import NudeDetector
        detector = NudeDetector()
    except Exception as e:
        # Fallback safe: log l'erreur sur stderr pour la visibilité de Node.js, mais retourne safe=True pour ne pas bloquer l'upload
        print(f"[WARNING] Erreur d'initialisation de NudeNet : {str(e)}", file=sys.stderr)
        return {"safe": True, "warning": f"Erreur d'initialisation de NudeNet : {str(e)}"}

    cap = cv2.VideoCapture(video_path)
    if not cap.isOpened():
        return {"safe": False, "reason": "Impossible d'ouvrir la vidéo."}

    fps = cap.get(cv2.CAP_PROP_FPS)
    if fps <= 0:
        fps = 25 # Fallback

    # Échantillonnage : 2 images toutes les secondes (double la fréquence d'analyse)
    frame_interval = int(fps / 2)
    if frame_interval <= 0:
        frame_interval = 1

    current_frame = 0
    explicit_labels = [
        # Nudité totale (EXPOSED)
        "EXPOSED_ANUS", "EXPOSED_BUTTOCKS", "EXPOSED_BREAST_F", "EXPOSED_GENITALIA_F", "EXPOSED_GENITALIA_M",
        "ANUS_EXPOSED", "BUTTOCKS_EXPOSED", "FEMALE_BREAST_EXPOSED", "FEMALE_GENITALIA_EXPOSED", "MALE_GENITALIA_EXPOSED",
        "exposed_anus", "exposed_buttocks", "exposed_breast_f", "exposed_genitalia_f", "exposed_genitalia_m",
        "anus_exposed", "buttocks_exposed", "female_breast_exposed", "female_genitalia_exposed", "male_genitalia_exposed",
        
        # Sous-vêtements / Lingerie coquine (COVERED)
        "BUTTOCKS_COVERED", "FEMALE_BREAST_COVERED", "FEMALE_GENITALIA_COVERED",
        "COVERED_BUTTOCKS", "COVERED_BREAST_F", "COVERED_GENITALIA_F",
        "buttocks_covered", "female_breast_covered", "female_genitalia_covered",
        "covered_buttocks", "covered_breast_f", "covered_generalia_f"
    ]
    score_threshold = 0.30

    temp_image_path = f"temp_frame_{os.getpid()}.jpg"

    while True:
        ret, frame = cap.read()
        if not ret:
            break

        if current_frame % frame_interval == 0:
            # Sauvegarder temporairement la frame pour NudeNet
            cv2.imwrite(temp_image_path, frame)
            
            try:
                # Détection
                detections = detector.detect(temp_image_path)
                
                # Vérifier si des éléments explicites sont présents
                for d in detections:
                    raw_label = d.get("class", d.get("label", ""))
                    score = d.get("score", 0.0)
                    
                    # Normaliser le label (majuscules, underscores à la place des espaces)
                    label = raw_label.upper().replace(" ", "_")
                    
                    # Mappages alternatifs pour assurer la compatibilité inter-versions de NudeNet
                    label_mappings = {
                        "EXPOSED_BREAST": "FEMALE_BREAST_EXPOSED",
                        "EXPOSED_VAGINA": "FEMALE_GENITALIA_EXPOSED",
                        "EXPOSED_PENIS": "MALE_GENITALIA_EXPOSED",
                        "EXPOSED_ANUS": "ANUS_EXPOSED",
                        "EXPOSED_BUTTOCKS": "BUTTOCKS_EXPOSED",
                        "BREAST_EXPOSED": "FEMALE_BREAST_EXPOSED",
                        "VAGINA_EXPOSED": "FEMALE_GENITALIA_EXPOSED",
                        "PENIS_EXPOSED": "MALE_GENITALIA_EXPOSED",
                    }
                    normalized_label = label_mappings.get(label, label)
                    
                    if (normalized_label in explicit_labels or label in explicit_labels or raw_label in explicit_labels) and score >= score_threshold:
                        cap.release()
                        if os.path.exists(temp_image_path):
                            os.remove(temp_image_path)
                        return {
                            "safe": False, 
                            "reason": f"Contenu explicite détecté ({raw_label}) avec une confiance de {int(score * 100)}%."
                        }
            except Exception as e:
                # Loguer dans stderr mais continuer l'analyse des autres frames
                print(f"Erreur d'analyse de la frame {current_frame} : {str(e)}", file=sys.stderr)
            finally:
                if os.path.exists(temp_image_path):
                    os.remove(temp_image_path)

        current_frame += 1

    cap.release()
    return {"safe": True}

if __name__ == "__main__":
    if len(sys.argv) < 2:
        print(json.dumps({"safe": False, "reason": "Chemin de la vidéo manquant."}))
        sys.exit(1)
        
    video_path = sys.argv[1]
    result = moderate_video(video_path)
    print(json.dumps(result))
