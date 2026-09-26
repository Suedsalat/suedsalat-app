import CarPlay
import Flutter
import UIKit

/// Verbindung zwischen CarPlay und der Dart-Seite (lib/services/carplay_service.dart).
///
/// Dart liefert die Folgenliste und spielt ab - dieselbe Wiedergabe wie am Handy (AudioPlayerService),
/// daher laufen Sperrbildschirm, "Läuft gerade" in CarPlay, Wiedergabeposition und Statistik ohne
/// Extra-Weg mit. Die "Läuft gerade"-Ansicht fuellt iOS selbst aus dem, was audio_service meldet.
final class CarPlayBridge {
  static let shared = CarPlayBridge()

  private var channel: FlutterMethodChannel?
  private var retryCount = 0

  /// Wird aufgerufen, sobald eine (neue) Folgenliste da ist.
  var onEpisodes: (([[String: Any]]) -> Void)?

  /// Nur solange das Auto verbunden ist, lohnt sich das Laden.
  var isConnected = false

  func attach(to messenger: FlutterBinaryMessenger) {
    let channel = FlutterMethodChannel(name: "eu.suedsalat.suedsalat_app/carplay", binaryMessenger: messenger)
    channel.setMethodCallHandler { [weak self] call, result in
      // Dart meldet: Liste hat sich geaendert (andere Folge laeuft, Folge zu Ende gehoert).
      if call.method == "episodesChanged" {
        if self?.isConnected == true { self?.requestEpisodes() }
        result(nil)
      } else {
        result(FlutterMethodNotImplemented)
      }
    }
    self.channel = channel
  }

  /// Folgenliste bei Dart anfragen. Direkt nach dem Start der App ist Dart evtl. noch nicht so weit
  /// (main() laeuft noch) - dann in kurzen Abstaenden erneut versuchen, hoechstens eine Minute lang.
  func requestEpisodes() {
    channel?.invokeMethod("episodes", arguments: nil) { [weak self] response in
      guard let self = self else { return }
      if let list = response as? [[String: Any]] {
        self.retryCount = 0
        self.onEpisodes?(list)
        return
      }
      guard self.isConnected, self.retryCount < 40 else { return }
      self.retryCount += 1
      DispatchQueue.main.asyncAfter(deadline: .now() + 1.5) { self.requestEpisodes() }
    }
  }

  func play(guid: String) {
    channel?.invokeMethod("play", arguments: ["guid": guid])
  }
}

/// CarPlay-Oberflaeche: eine Liste der Folgen (neueste oben), Antippen spielt ab und oeffnet
/// "Läuft gerade". Bewusst nur Folgen, wie bei Android Auto - alles andere lenkt beim Fahren ab.
class CarPlaySceneDelegate: UIResponder, CPTemplateApplicationSceneDelegate {
  private var interfaceController: CPInterfaceController?
  private var listTemplate: CPListTemplate?
  private let imageCache = NSCache<NSString, UIImage>()

  @available(iOS 14.0, *)
  func templateApplicationScene(
    _ templateApplicationScene: CPTemplateApplicationScene,
    didConnect interfaceController: CPInterfaceController
  ) {
    self.interfaceController = interfaceController
    let template = CPListTemplate(title: "Südsalat", sections: [])
    template.emptyViewTitleVariants = ["Folgen werden geladen …"]
    listTemplate = template
    interfaceController.setRootTemplate(template, animated: false, completion: nil)

    let bridge = CarPlayBridge.shared
    bridge.isConnected = true
    bridge.onEpisodes = { [weak self] episodes in
      DispatchQueue.main.async { self?.show(episodes) }
    }
    bridge.requestEpisodes()
  }

  @available(iOS 14.0, *)
  func templateApplicationScene(
    _ templateApplicationScene: CPTemplateApplicationScene,
    didDisconnectInterfaceController interfaceController: CPInterfaceController
  ) {
    CarPlayBridge.shared.isConnected = false
    CarPlayBridge.shared.onEpisodes = nil
    self.interfaceController = nil
    listTemplate = nil
  }

  @available(iOS 14.0, *)
  private func show(_ episodes: [[String: Any]]) {
    guard let template = listTemplate else { return }
    let items: [CPListItem] = episodes.prefix(CPListTemplate.maximumItemCount).compactMap {
      (episode: [String: Any]) -> CPListItem? in
      guard let guid = episode["guid"] as? String, let title = episode["title"] as? String else { return nil }
      let item = CPListItem(text: title, detailText: episode["detail"] as? String, image: nil)
      item.isPlaying = (episode["playing"] as? Bool) ?? false
      if let progress = episode["progress"] as? Double {
        item.playbackProgress = CGFloat(min(max(progress, 0), 1))
      }
      if let imageUrl = episode["imageUrl"] as? String {
        loadImage(imageUrl) { image in item.setImage(image) }
      }
      item.handler = { [weak self] _, completion in
        CarPlayBridge.shared.play(guid: guid)
        self?.showNowPlaying()
        completion()
      }
      return item
    }
    template.emptyViewTitleVariants = ["Keine Folgen gefunden"]
    template.updateSections([CPListSection(items: items)])
  }

  @available(iOS 14.0, *)
  private func showNowPlaying() {
    guard let controller = interfaceController else { return }
    let nowPlaying = CPNowPlayingTemplate.shared
    if controller.topTemplate !== nowPlaying {
      controller.pushTemplate(nowPlaying, animated: true, completion: nil)
    }
  }

  /// Cover laden, auf die Groesse verkleinern, die CarPlay in Listen zeigt, und zwischenspeichern.
  @available(iOS 14.0, *)
  private func loadImage(_ urlString: String, completion: @escaping (UIImage) -> Void) {
    if let cached = imageCache.object(forKey: urlString as NSString) {
      completion(cached)
      return
    }
    guard let url = URL(string: urlString) else { return }
    URLSession.shared.dataTask(with: url) { [weak self] data, _, _ in
      guard let data = data, let image = UIImage(data: data) else { return }
      let size = CPListItem.maximumImageSize
      let scaled = UIGraphicsImageRenderer(size: size).image { _ in
        image.draw(in: CGRect(origin: .zero, size: size))
      }
      self?.imageCache.setObject(scaled, forKey: urlString as NSString)
      DispatchQueue.main.async { completion(scaled) }
    }.resume()
  }
}
