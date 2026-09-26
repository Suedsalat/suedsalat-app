import Flutter
import UIKit

/// Handy-Fenster. Zeigt die Engine aus dem AppDelegate an (siehe dort, noetig fuer CarPlay) statt
/// ueber das Storyboard eine eigene zu erzeugen - deshalb steht in der Info.plist fuer diese Szene
/// kein UISceneStoryboardFile mehr. Weil der FlutterViewController mit genau dieser Engine im
/// Fenster haengt, meldet Flutter sie automatisch fuer die Szenen-Ereignisse der Plugins an.
class SceneDelegate: FlutterSceneDelegate {
  override func scene(
    _ scene: UIScene,
    willConnectTo session: UISceneSession,
    options connectionOptions: UIScene.ConnectionOptions
  ) {
    if let windowScene = scene as? UIWindowScene,
       let appDelegate = UIApplication.shared.delegate as? AppDelegate {
      let window = UIWindow(windowScene: windowScene)
      // Eine Engine kann nur einen FlutterViewController haben: Baut iOS das Fenster neu auf
      // (Szene getrennt und wieder verbunden), den vorhandenen weiterverwenden.
      let controller = appDelegate.flutterEngine.viewController
        ?? FlutterViewController(engine: appDelegate.flutterEngine, nibName: nil, bundle: nil)
      window.rootViewController = controller
      self.window = window
      window.makeKeyAndVisible()
    }
    super.scene(scene, willConnectTo: session, options: connectionOptions)
  }
}
