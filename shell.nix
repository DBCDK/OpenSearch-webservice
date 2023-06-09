let
  nixpkgs = builtins.fetchTarball https://github.com/NixOS/nixpkgs/archive/nixos-22.11.tar.gz;
  pkgs = import nixpkgs {};
  osPHP = pkgs.php.withExtensions ({ enabled, all }: enabled ++ [ all.imagick all.memcached]);
in
  pkgs.mkShell {
      name = "opensarech build shell ";
      packages = with pkgs; [ osPHP osPHP.packages.composer ];
  } 
